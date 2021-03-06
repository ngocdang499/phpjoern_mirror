<?php declare( strict_types = 1);

// needed for the flag constants -- i.e., essentially get_flag_info()
// used in csv_format_flags()
require 'util.php';

/**
 * This class manages the two CSV files for exporting nodes. It
 * iterates over ASTs, generates corresponding entries and writes
 * these to the CSV files.
 *
 * @author Malte Skoruppa <skoruppa@cs.uni-saarland.de>
 */
class CSVExporter {

  /** Constant for Neo4J format (to be used with neo4j-import) */
  const NEO4J_FORMAT = 0;
  /** Constant for jexp format (to be used with batch-import) */
  const JEXP_FORMAT = 1;

  /** Used format -- defaults to Neo4J */
  private $format = self::NEO4J_FORMAT;

  /** Delimiter for columns in CSV files */
  private $csv_delim = ",";
  /** Delimiter for arrays in CSV files */
  private $array_delim = ";";

  /** Default name of node file */
  const NODE_FILE = "nodes.csv";
  /** Default name of relationship file */
  const REL_FILE = "rels.csv";

  /** Handle for the node file */
  private $nhandle;
  /** Handle for the relationship file */
  private $rhandle;
  /** Node counter */
  private $nodecount = 0;

  /** Type of file nodes */
  const FILE = "File";
  /** Type of directory nodes */
  const DIR = "Directory";

  /**
   * Constructor, creates file handlers.
   *
   * @param $format     Format to use for export (neo4j or jexp)
   * @param $nodefile   Name of the nodes file
   * @param $relfile    Name of the relationships file
   * @param $startcount *Once* when creating the CSVExporter instance,
   *                    the starting node index may be chosen. Defaults to 0.
   */
  public function __construct( $format = self::NEO4J_FORMAT, $nodefile = self::NODE_FILE, $relfile = self::REL_FILE, $startcount = 0) {

    $this->format = $format;
    $this->nodecount = $startcount;

    foreach( [$nodefile, $relfile] as $file)
      if( file_exists( $file))
	error_log( "[WARNING] $file already exists, overwriting it.");

    if( false === ($this->nhandle = fopen( $nodefile, "w")))
      throw new IOError( "There was an error opening $nodefile for writing.");
    if( false === ($this->rhandle = fopen( $relfile, "w")))
      throw new IOError( "There was an error opening $relfile for writing.");

    // if format is non-default, adapt delimiters and headers
    if( $this->format === self::JEXP_FORMAT) {
      $this->csv_delim = "\t";
      $this->array_delim = ",";

      fwrite( $this->nhandle, "id:int{$this->csv_delim}type{$this->csv_delim}flags:string_array{$this->csv_delim}lineno:int{$this->csv_delim}code{$this->csv_delim}childnum:int{$this->csv_delim}funcid:int{$this->csv_delim}endlineno:int{$this->csv_delim}name{$this->csv_delim}doccomment\n");
      fwrite( $this->rhandle, "start{$this->csv_delim}end{$this->csv_delim}type\n");
    }
    else {
      fwrite( $this->nhandle, "id:ID{$this->csv_delim}type{$this->csv_delim}flags:string[]{$this->csv_delim}lineno:int{$this->csv_delim}code{$this->csv_delim}childnum:int{$this->csv_delim}funcid:int{$this->csv_delim}endlineno:int{$this->csv_delim}name{$this->csv_delim}doccomment\n");
      fwrite( $this->rhandle, ":START_ID{$this->csv_delim}:END_ID{$this->csv_delim}:TYPE\n");
    }
  }

  /**
   * Destructor, closes file handlers.
   */
  public function __destruct() {

    fclose( $this->nhandle);
    fclose( $this->rhandle);
  }

  /**
   * Exports a syntax tree into two CSV files as
   * described in https://github.com/jexp/batch-import/
   *
   * @param $ast      The AST to export.
   * @param $nodeline Indicates the nodeline of the parent node. This
   *                  is necessary when $ast is a plain value, since
   *                  we cannot get back from a plain value to the
   *                  parent node to learn the line number.
   * @param $childnum Indicates that this node is the $childnum'th
   *                  child of its parent node (starting at 0).
   * @param $funcid   If the AST to be exported is part of a function,
   *                  the node id of that function.
   * 
   * @return The root node index of the exported AST (i.e., the value
   *         of $this->nodecount at the point in time where this
   *         function was called.)
   */
  public function export( $ast, $nodeline = 0, $childnum = 0, $funcid = null) : int {

    // (1) if $ast is an AST node, print info and recurse
    // An instance of ast\Node declares:
    // $kind (integer, name can be retrieved using ast\get_kind_name())
    // $flags (integer, corresponding to a set of flags for the current node)
    // $lineno (integer, starting line number)
    // $children (array of child nodes)
    // Additionally, an instance of the subclass ast\Node\Decl declares:
    // $endLineno (integer, end line number of the declaration)
    // $name (string, the name of the declared function/class)
    // $docComment (string, the preceding doc comment)
    if( $ast instanceof ast\Node) {

      $nodetype = ast\get_kind_name( $ast->kind);
      $nodeline = $ast->lineno;

      $nodeflags = "";
      if( ast\kind_uses_flags( $ast->kind)) {
	$nodeflags = $this->csv_format_flags( $ast->kind, $ast->flags);
      }

      // for decl nodes:
      if( isset( $ast->endLineno)) {
	$nodeendline = $ast->endLineno;
      }
      if( isset( $ast->name)) {
	$nodename = $ast->name;
      }
      if( isset( $ast->docComment)) {
	$nodedoccomment = $this->quote_and_escape( $ast->docComment);
      }

      // store node, export all children and store the relationships
      $rootnode = $this->store_node( $nodetype, $nodeflags, $nodeline, null, $childnum, $funcid, $nodeendline, $nodename, $nodedoccomment);

      // If this node is a function/method/closure declaration, set $funcid.
      // Note that in particular, the decl node *itself* does not have $funcid set to its own id;
      // this is intentional. The *declaration* of a function/method/closure itself is part of the
      // control flow of the outer scope: e.g., a closure declaration is part of the control flow
      // of the function it is declared in, or a function/method declaration is part of the control flow
      // of the pseudo-function representing the top-level code it is declared in.
      if( $ast->kind === ast\AST_FUNC_DECL || $ast->kind === ast\AST_METHOD || $ast->kind === ast\AST_CLOSURE)
	$funcid = $rootnode;

      foreach( $ast->children as $i => $child) {
	$childnode = $this->export( $child, $nodeline, $i, $funcid);
	$this->store_rel( $rootnode, $childnode, "PARENT_OF");
      }
    }

    // if $ast is not an AST node, it should be a plain value
    // see http://php.net/manual/en/language.types.intro.php

    // (2) if it is a plain value and more precisely a string, escape
    // it and put quotes around the content
    else if( is_string( $ast)) {

      $nodetype = gettype( $ast); // should be string
      $rootnode = $this->store_node( $nodetype, null, $nodeline, $this->quote_and_escape( $ast), $childnum, $funcid);
    }

    // (3) If it a plain value and more precisely null, there's no corresponding code per se, so we just print the type.
    // Note that this branch is NOT relevant for statements such as, e.g.,
    // $n = null;
    // Indeed, in this case, null would be parsed as an AST_CONST with appropriate children (see test-own/assignments.php)
    // Rather, we encounter a null node when things are undefined, such as, for instance, an array element's key,
    // a class that does not use an "extends" or "implements" statement, a function that has no return value, etc.
    else if( $ast === null) {

      $nodetype = gettype( $ast); // should be the string "NULL"
      $rootnode = $this->store_node( $nodetype, null, $nodeline, null, $childnum, $funcid);
    }

    // (4) if it is a plain value but not a string and not null, cast to string and store the result as $nodecode
    // Note: I expected at first that such values may be booleans, integers, floats/doubles, arrays, objects, or resources.
    // However, testing this on test-own/assignments.php, I found that this branch is only taken for integers and floats/doubles;
    // * for booleans (as for the null value), AST_CONST is used;
    // * for arrays, AST_ARRAY is used;
    // * for objects and resources, which can only be instantiated via the
    //   new operator or function calls, the corresponding statement is
    //   decomposed as an AST, i.e., we get AST_NEW or AST_CALL nodes with appropriate children.
    // Thus, so far I have only seen this branch taken for integers and floats/doubles.
    // We print these similarly as strings, but without quotes.
    else {

      $nodetype = gettype( $ast);
      $nodecode = (string) $ast;
      $rootnode = $this->store_node( $nodetype, null, $nodeline, $nodecode, $childnum, $funcid);
    }

    return $rootnode;
  }

  /*
   * Helper function to write a node to a CSV file and increase the node counter
   *
   * Note on node types: there are different types of nodes:
   * - AST_* nodes with children of their own; these can be divided in two kinds:
   *   i. normal AST nodes
   *   ii. declaration nodes (see https://github.com/nikic/php-ast/issues/12)
   * - strings, for names of variables and constants, for the content
   *   of variables, etc.
   * - NULL nodes, for undefined nodes in the AST
   * - integers and floats/doubles, i.e., plain types
   * - File and Directory nodes, for files and directories,
   *   representing the global code structure (we use store_filenode()
   *   and store_dirnode() for these)
   *
   * @param type     The node type (mandatory)
   * @param flags    The node's flags (mandatory, but may be empty)
   * @param lineno   The node's line number (mandatory)
   * @param code     The node code (optional)
   * @param childnum The child number of this node, i.e., how many older siblings it has ;-) (optional)
   * @param funcid   The id of the function node that this node is a part of (optional)
   *
   * Additionally, only for decl nodes, i.e., function and class declarations (thus obviously optional):
   * @param endlineno  The node's last line number
   * @param name       The function's or class's name
   * @param doccomment The function's or class's doc comment
   *
   * @return The index of the stored node.
   */
  private function store_node( $type, $flags, $lineno, $code = null, $childnum = null, $funcid = null, $endlineno = null, $name = null, $doccomment = null) : int {

    fwrite( $this->nhandle, "{$this->nodecount}{$this->csv_delim}{$type}{$this->csv_delim}{$flags}{$this->csv_delim}{$lineno}{$this->csv_delim}{$code}{$this->csv_delim}{$childnum}{$this->csv_delim}{$funcid}{$this->csv_delim}{$endlineno}{$this->csv_delim}{$name}{$this->csv_delim}{$doccomment}\n");

    // return the current node index, *then* increment it
    return $this->nodecount++;
  }

  /**
   * Stores a file node, increases the node counter and returns the
   * index of the stored file node.
   *
   * @param $filename The file's name, which will be stored under the
   *                  'name' poperty of the File node.
   *
   * @return The index of the stored file node.
   */
  public function store_filenode( $filename) : int {

    return $this->store_node( self::FILE, null, null, null, null, null, null, $this->quote_and_escape( $filename), null);
  }

  /**
   * Stores a directory node, increases the node counter and returns the
   * index of the stored directory node.
   *
   * @param $dirname The directory's name, which will be stored under the
   *                 'name' poperty of the Directory node.
   *
   * @return The index of the stored directory node.
   */
  public function store_dirnode( $filename) : int {

    return $this->store_node( self::DIR, null, null, null, null, null, null, $this->quote_and_escape( $filename), null);
  }

  /*
   * Writes a relationship to a CSV file.
   *
   * @param start   The starting node's index
   * @param end     The ending node's index
   * @param type    The relationship's type
   */
  public function store_rel( $start, $end, $type) {

    fwrite( $this->rhandle, "{$start}{$this->csv_delim}{$end}{$this->csv_delim}{$type}\n");
  }

  /**
   * Replaces ambiguous signs in $str, namely
   * \ -> \\
   * " -> \"
   * TODO because of a bug in neo4j-import, we also
   * replace newlines for now:
   * \n -> \\n
   * \r -> \\r
   * Additionally, puts quotes around the resulting string.
   *
   * @param $str  The string to be quoted and escaped
   *
   * @return $str The quoted and escaped string
   */
  private function quote_and_escape( $str) : string {

    $str = str_replace( "\\", "\\\\", $str);

    // TODO usually multi-line fields *should* be fine provided that
    // they are quoted properly. However this does not appear to work
    // right now with neo4j-import
    // (https://github.com/neo4j/neo4j/issues/5028), so let's escape
    // newlines as a workaround for now...
    if( $this->format === self::NEO4J_FORMAT) {
      $str = str_replace( "\n", "\\n", $str);
      $str = str_replace( "\r", "\\r", $str);
    }

    $str = "\"".str_replace( "\"", "\\\"", $str)."\"";

    return $str;
  }

  /*
   * Slight modification of format_flags() from php-ast's util.php
   *
   * Given a kind (e.g., AST_NAME or AST_METHOD) and a set of flags
   * (say, NAME_NOT_FQ or MODIFIER_PUBLIC | MODIFIER_STATIC), both of
   * which are represented as an integer, return the named list of flags
   * as a comma-separated list.
   *
   * Some flags are exclusive (say, NAME_FQ and NAME_NOT_FQ, for the
   * AST_NAME kind), while others are combinable (say, MODIFIER_PUBLIC
   * and MODIFIER_STATIC, for the AST_METHOD kind). Each kind has at
   * most one exclusive flag, but may have more than one combinable
   * flag. Additionally, no kind uses both exclusive and combinable
   * flags, i.e., the set of kinds using exclusive flags and the set of
   * kinds using cominable flags are disjunct.
   *
   * More information on flags can be found by looking at the source
   * code of the function get_flag_info() in util.php
   *
   * @param kind  An AST node type, represented as an integer
   * @param flags Flags pertaining to the current AST node, represented
   *              as an integer
   *
   * @return A comma-separated list of named flags.
   */
  private function csv_format_flags( int $kind, int $flags) : string {

    list( $exclusive, $combinable) = get_flag_info();
    if( isset( $exclusive[$kind])) {
      $flagInfo = $exclusive[$kind];
      if( isset( $flagInfo[$flags])) {
	return $flagInfo[$flags];
      }
    }

    else if( isset( $combinable[$kind])) {
      $flagInfo = $combinable[$kind];
      $names = [];
      foreach( $flagInfo as $flag => $name) {
	if( $flags & $flag) {
	  $names[] = $name;
	}
      }
      if( !empty($names)) {
	return implode( $this->array_delim, $names);
      }
    }

    // If the given $kind does not use either exclusive or combinable
    // flags, or if it does, but the given $flags did not yield any
    // flags for the given $kind, we arrive here. In principle $flags
    // should always be 0 at this point.
    // TODO: for ast\AST_ARRAY_ELEM (kind=525) and ast\AST_CLOSURE_VAR
    // (kind=2049), the flag might be 1, meaning "by-reference", but
    // this cannot be properly formated since no appropriate names are
    // declared in util.php. Ask Niki about that. Maybe submit patch.
    if( $flags === 0)
      return "";
    else
      return "\"[WARNING] Unexpected flags for kind: kind=$kind and flags=$flags\"";
  }

  /**
   * Returns the number of nodes created so far.
   *
   * @return The number of nodes created so far.
   */
  public function getNodeCount() : int {
    return $this->nodecount;
  }

}

/**
 * Custom error class for IO errors
 */
class IOError extends Error {}
