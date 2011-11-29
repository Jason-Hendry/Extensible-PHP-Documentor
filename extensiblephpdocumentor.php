<?php
/**
 * Base documentor class, provides basic operation but should be extended and customized
 */
class ExtensiblePHPDocumentor {
  const OUTPUT_FORMAT_HTML = 0x01;
  const OUTPUT_FORMAT_CREOLE = 0x02;
  public $saveToFile;
  public $outputFormat;
  public function __construct() {
    $this->saveToFile = false;
    $this->outputFormat = self::OUTPUT_FORMAT_HTML; 
  }
  public function DocumentFile($file) {
    $parser = new EPDParser();
    $result = $parser->parseFile($file);
    
  }
  public function DocumentDirectory($dir,$recursive=true) {
    
  }
}

/**
 * Base PHP Document Object
 */
class EPDObject {
  public $parent;
  public $variables = array();
  public $name;
  function __construct($parent) {
    $this->parent = $parent;
  }
  function addVariable($var) {
    $this->variables[] = $var;
  }
}
/**
 * PHP File Object
 */
class EPDFile extends EPDObject {
  public $type = 'open';
  public $functions = array();
  public $classes = array();
  public $parent;
  public $structure = array();
  public $comment;
  public $filename = '';
  function addVariable($var) {
    $this->variables[] = $var;
  }
  function addFunction($fnt) {
    $this->functions[] = $fnt;
  }
  function addClass($cls) {
    $this->classes[] = $cls;
  }
  function addStructure($str) {
    $this->structure[] = $str;
  }
  function display() {
    echo "<div>$this->type:\n$this->comment\n";
    foreach($this->variables as $v)
      $v->display();
    foreach($this->functions as $f) {
      $f->display();
    }
    foreach($this->classes as $c) {
      $c->display();
    }
    echo "</div>\n";
  }
}
/**
 * PHP Function Object
 */
class EPDFunction extends EPDObject {
  public $type = 'function';  
  public $params;
  public $visibility;
  public $comment;
  function display() {
    $pstr = implode(', ',$this->params);
    echo "<div><h2><em>$this->visibility</em> {$this->name} ($pstr)</strong></h2><p>$this->comment</p></div>";
  }
}
class EPDClass extends EPDObject {
  public $type = 'class';
  public $extends;
  public $implements = array();
  public $variables = array();
  public $functions = array();
  function addFunction($fnt) {
    $this->functions[] = $fnt;
  }
  function display() {
    echo "<section><h1>Class: $this->name</h1>\n<br/>";
    echo "Extends: $this->extends<br/>\n";
    echo "Implements: ".implode(', ',$this->implements)."<br/>\n";
    echo "Comment: <br/><pre>$this->comment</pre><br/>\n";
    foreach($this->variables as $v)
      $v->display();
    foreach($this->functions as $f) {
      $f->display();
    }
    echo "</section>";
  }  
}

class EPDVariable extends EPDObject {
  public $comment;
  public $visibility;
  public $scope;
  public $parent;
  public $default;
  function __construct($name,$parent) {
    $this->name = $name;
    $this->parent = $parent;
  }
  function display() {
    echo "<div><p>$this->scope $this->visibility <strong>$this->name</strong> ($this->default)\n$this->comment\n</p></div>";
  }
}

class EPDParser {
  public function parseFile($file) {
    $object = new EPDFile(false);
    $this->parse(file_get_contents($file),$object);
    return $object;
  }
  public function parse($source,&$object) {
    $inlineHTML = '';
    $alltokens = token_get_all($source);
    $inPHP = false;
    $cur = &$object;
    $lastComment = '';
    
    $tokens = array();
    for($i=0;$i<count($alltokens);$i++) {
      //print_r($alltokens[$i]);
      if(is_string($alltokens[$i]) || $alltokens[$i][0] != 371 )
        $tokens[] = $alltokens[$i];
    }
      
    for($i=0;$i<count($tokens);$i++) {
      $t = $tokens[$i];
      list($name,$txt) = toTokenBits($t);
        // echo str_pad($cur->type,12,' ').str_pad($name,20,' ').str_replace("\n",'',$txt)."\n";
      
      switch($name) {
        case 'T_INLINE_HTML':
          $inlineHTML .= $t[1];
          break;
        case 'T_OPEN_TAG':
          $inPHP = true;
          break;
        case 'T_VARIABLE':
          $var = new DocsPHPVariable($t[1],$cur);
          $var->comment = $lastComment; $lastComment = false;
          list($pname,$ptxt) = toTokenBits($tokens[$i-1]);
          if(in_array($pname, array('T_STATIC','T_PUBLIC','T_PRIVATE','T_PROTECTED'))) {
            $var->visibility = $ptxt;
          }
          $j = $i;
          $assignment = $tokens[$i+1]==='=';
          $default = false;
          if($i-2 > 0 && is_array($tokens[$i-2]) && token_name($tokens[$i-2][0]) == 'T_GLOBAL')
            $var->scope = 'global';
          //echo "Test Variable:\n";
          while(1) {
            $j++;
            if($j>count($tokens)) break;
            list($name,$txt) = toTokenBits($tokens[$j]);
            if($name == ';') break;          
            if($name = 'T_CONSTANT_ENCAPSED_STRING' && $assignment && $tokens[$j+1]==';') {
              $default = $txt;
              $i=$j+1; // Skip to token after ;
              break;
            }
          }
          if($default)
            $var->default = $default;
          if($cur->type == 'open' || $cur->type == 'class')
            $cur->addVariable($var);
          break;
        case 'T_FUNCTION':
          $fnt = new DocsPHPFunction($cur);
          $functionName = false;
          $inParams = false;
          $params = array();
          list($pname,$ptxt) = toTokenBits($tokens[$i-1]);
          if(in_array($pname, array('T_STATIC','T_PUBLIC','T_PRIVATE','T_PROTECTED'))) {
            $fnt->visibility = $ptxt;
          }
          $j=$i;
          while(1) {
            $j++;
            if($j>=count($tokens)) break;
            list($name,$txt) = toTokenBits($tokens[$j]);
            if($name == '{') break;
            
            // Get Function Name
            if($name == 'T_STRING' && !$functionName)
              $functionName = $txt;
            // Start param list
            if($name == '(')
              $inParams = true;
            if($name == ')')
              $inParams = false;
            // Parameter names
            if($name == 'T_VARIABLE' && $inParams) {
              if($tokens[$j+1]=='=') {
                list($name2,$txt2) = toTokenBits($tokens[$j+2]);
                $params[] = $txt.'='.$txt2;            
              } else
                $params[] = $txt;
            }
          }
          $fnt->name = $functionName;
          $fnt->params = $params;
          $fnt->comment = $lastComment; $lastComment = false;
          $cur->addFunction($fnt);
          $cur = &$fnt;
          $i=$j;
          break;
        case 'T_CLASS':
          $cls = new DocsPHPClass($cur);
          $className = false;
          $extends = false;
          $implements = false;
          $j=$i;
          while(1) {
            $j++;
            if($j>=count($tokens)) break;
            list($name,$txt) = toTokenBits($tokens[$j]);
            if($name == '{') break;
            if($name == 'T_STRING' && !$className)
              $className = $txt;
            if($name == 'T_EXTENDS')
              $extends = true;
            if($extends === true && $name == 'T_STRING')
              $extends = $txt;
            if($name == 'T_IMPLEMENTS')
              $implements = array();
            if(is_array($implements) && $name == 'T_STRING')
              $implements[] = $txt;
          }
          $cls->name = $className;
          $cls->comment = $lastComment; $lastComment = false;
          $cls->extends = $extends;
          $cls->implements = $implements;
          $cur->addClass($cls);
          $cur = &$cls;
          $i=$j; // Skip to { token
          break;
        case 'T_FOREACH':
        case 'T_FOR':
        case 'T_WHILE':
        case 'T_IF':
        case 'T_ELSE':
        case 'T_ELSEIF':
          $str = new DocsPHP($cur);
          $str->type = $t[1];
          $cur->addStructure($str);
          
          $j=$i;
          while(1) {
            $j++;
            $t = $tokens[$j];
            if($j>count($tokens) || $t == '{' || $t == ';')
              break;
          }
          if($t=='{')
            $cur = &$str;
          break;
        case '}';
          $parent = &$cur->parent;
          $cur = &$parent;
          break;
        case 'T_CURLY_OPEN':
          $cur = new DocsPHP($cur);
          $cur->type = 'other';
          break;
        case ';':
        case 'T_GLOBAL':
          break;
        case 'T_COMMENT':
        case 'T_DOC_COMMENT':
          $lastComment = $t[1];
          break;
        default:
  //        echo is_array($t) ? "$name:".trim($t[1]).' - '.trim($t[2])."\n" : "$t\n";    
      }
      
    }
    return array(
      'inlineHTML' => $inlineHTML,
      'object' => $object,
    );
  }
}
