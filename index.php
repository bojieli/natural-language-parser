<!DOCTYPE html>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>语法分析器</title>
</head>
<body>
<h2>语法分析器 for 自然语言理解</h2>
<?php
error_reporting(E_ALL);
$console = "=== The console for runtime details ===\n";
function warning($msg) {
	global $console;
	$console .= "Warning: $msg\n";
}
function error($msg) {
	global $console;
	$console .= "Error: $msg\n";
}
function console($msg) {
	global $console;
	$console .= "$msg\n";
}
function debug_console($msg) {
	global $console;
//	$console .= "[DEBUG] $msg\n";
}
function internal_error($msg) {
	global $console;
	$console .= "Internal Error: $msg\n";
}

if (empty($_POST['tokens'])) {
	$tokens = file_get_contents("token.xml");
} else {
	$tokens = $_POST['tokens'];
}
$token_xml = new SimpleXMLElement($tokens);
$s = (array)$token_xml->s;
$sym = (array)$token_xml->sym;
console("Symbol table initialized with ".count($s)." terminal, ".count($sym)." non-terminals.");

//========== dict ===============
if (empty($_POST['lexicon'])) {
	$lexicon = file_get_contents("lexicon.xml");
} else {
	$lexicon = $_POST['lexicon'];
}
$lex_xml = new SimpleXMLElement($lexicon);
$lex = (array)$lex_xml;
$lex = $lex["lex"];

$dict = array();
foreach ($lex as $w) {
	$w = substr($w, 1, -1);
	$tmp = explode(' ', $w);
	$tmp[1] = strtoupper($tmp[1]);
	if (!isset($sym[$tmp[1]]))
		continue; // symbol not exist
	$tmp[0] = strtolower($tmp[0]);
	$dict[$tmp[0]] = $tmp[1];
}
console("Dictionary initialized with ".count($dict)." words.");

//=========== grammar ==============
if (empty($_POST['grammar'])) {
	$grammar = file_get_contents("grammar.xml");
} else {
	$grammar = $_POST['grammar'];
}
$grammar_xml = new SimpleXMLElement($grammar);
$gram = (array)$grammar_xml;
$gram = $gram["rule"];

$rule = array();
foreach ($gram as $r) {
	$r = strtoupper($r);
	$r = substr($r, 1, -1);
	$tmp = explode(' ', $r);
	$from = $tmp[0];
	if (!isset($sym[$from]) && !isset($s[$from])) {
		warning("Invalid token ".$from." in rule ".$r);
		continue;
	}
	unset($tmp[0]);
	$flag = true;
	foreach ($tmp as $i => $token) {
		$tmp[$i] = $token = trim($token, " ()");
		if (!isset($sym[$token]) && !isset($s[$token])) {
			$flag = false;
			warning("Invalid token ".$token." in rule ".$r);
		}
	}
	if ($from == $tmp[1]) {
		warning("Recursive source token $token in rule $r (However the program can handle it)");
	}
	if ($flag) {
		$rule[$from][] = $tmp;
	}
}
console("Grammar initialized with ".count($rule)." rules.");

//=========== sentence =============
if (!empty($_POST['sentence'])) {
	$sentence = trim($_POST['sentence']);
} else {
	$sentence = 'I want the milk.';
}
$word = explode(' ', $sentence);
$tword = array(); // tokenized sentence
foreach ($word as $i => $w) {
	$word[$i] = strtolower($w);
	$newword = trim($word[$i], ".,?!: ");
	if ($newword !== $word[$i]) {
		console("Word \"".$word[$i]."\" trimmed to \"$newword\"");
		$word[$i] = $newword;
	}
	if (!preg_match('/^[a-z]+$/', $word[$i])) {
		warning("Invalid word \"".$word[$i]."\" in input");
		unset($word[$i]); // invalid
		continue;
	}
	if (!isset($dict[$word[$i]])) {
		warning("Word \"".$word[$i]."\" is not found in dict.");
		unset($word[$i]);
	} else {
		$tword[] = $dict[$word[$i]];
	}
}
console("Sentence \"".implode(' ', $word)."\" parsed as: ".implode(' ', $tword));

//=========== parse ==============

$d = array(); // dynamic programming 
// $d[$len][$left] = array(possible tokens) of [left..len+left-1]
$m = array(); // matches
// $m[$len][$left][$token] = array(end points of each component)

$n = count($word);
function match_component($token, $rule_no, $comp, $orig, $start, $end) {
	global $d;
	global $m;
	global $n;
	global $rule;
	$r = $rule[$token][$rule_no];
	$comp_count = count($r);
	if ($start + $comp_count - $comp >= $end) // the unmatched components have no words left
		return false;
	if ($comp == $comp_count) { // only one possible to match the last component
		if (in_array($r[$comp], $d[$end - $start][$start])) {
			$m[$end-$orig][$orig][$token][$comp] = $end; // record success
			return true;
		}
		else return false;
	}
	for ($l=1; $l<=$end-$start; $l++) {
		if (in_array($r[$comp], $d[$l][$start])) { // if match this component
			if (match_component($token, $rule_no, $comp + 1, $orig, $start + $l, $end)) { // try to match next
				$m[$end-$orig][$orig][$token][$comp] = $start + $l; // record success
				return true;
			}
		}
	}
	return false;
}

function match_rule($len, $left, $from) {
	global $rule;
	global $m;
	if (empty($rule[$from]))
		return false;
	foreach ($rule[$from] as $rule_no => $r) {
		if (match_component($from, $rule_no, 1, $left, $left, $left + $len)) {
			$m[$len][$left][$from]['rule_no'] = $rule_no;
			return true;
		}
	}
	return false;
}

for ($len=1; $len<=$n; $len++) {
	for ($left=0; $left<=$n-$len; $left++) {
		if ($len == 1)
			$d[1][$left] = array($tword[$left]); // initialize as the token of the word
		else
			$d[$len][$left] = array();

		$new = false;
		do {
			$new = false;
			foreach ($rule as $from => $r) {
				if (!in_array($from, $d[$len][$left]) && match_rule($len, $left, $from)) {
					$d[$len][$left][] = $from;
					$new = true;
				}
			}
		} while($new);
		$log = "Phrase \"".implode(' ', array_slice($word, $left, $len))."\" match";
		if (empty($d[$len][$left]))
			$log .= " no token";
		else {
			foreach ($d[$len][$left] as $token) {
				$log .= ' ('.$token.'=';
				if ($len == 1)
					$log .= $word[$left];
				else
					$log .= implode(' ',$rule[$token][$m[$len][$left][$token]['rule_no']]);
				$log .= ')';
			}
		}
		console($log);
	}
}

//=========== generate tree ======
function spacestr($len) {
	$str = '';
	for ($i=0; $i<$len; $i++)
		$str .= ' ';
	return $str;
}

// $m[$len][$left][$token] = array(end points of each component)
function gen_tree($len, $left, $token, $depth) {
	global $m;
	global $rule;
	global $word;
	global $tword;
	if ($len == 1 && $token == $tword[$left]) {
		return spacestr($depth*2).'('.$tword[$left].' '.$word[$left].')'."\n";
	}
	if (empty($m[$len][$left][$token])) {
		internal_error("Building syntax tree: m(len=$len left=$left token=$token) is empty");
	}
	$thism = $m[$len][$left][$token];
	$r = $rule[$token][$thism['rule_no']];
	$retstr = spacestr($depth*2).'('.$token."\n";
	$thism[0] = $left;
	//$thism[count($r)] = count($word);
	for ($comp=1; $comp<=count($r); $comp++) {
		if ($thism[$comp] <= $thism[$comp-1]) {
			internal_error("Building syntax tree: m($len $left $token): $comp => ".$thism[$comp].', '.($comp-1).' => '.$thism[$comp-1]);
		}
		$retstr .= gen_tree($thism[$comp]-$thism[$comp-1], $thism[$comp-1], $r[$comp], $depth + 1);
	}
	$retstr .= spacestr($depth*2).')'."\n";

	global $word;
	console("Tree for \"".implode(' ', array_slice($word, $left, $len))."\":\n".$retstr);
	return $retstr;
}

$matched = false;
$tree_str = '';
if (isset($m[$n][0])) {
foreach ($m[$n][0] as $token => $arr) {
	if (isset($s[$token])) {
		console("Output parse result for sentence $token:");
		$tree_str .= gen_tree($n, 0, $token, 0)."\n";
		$matched = true;
	}
}
}
if (!$matched) {
	$tree_str = "The given sentence cannot be parsed...\n";
	console($tree_str);
}

debug_console("tokens: ".serialize($d));
debug_console("diving points of token components: ".serialize($m));

//=========== output =============
?>
<style>td.comment {padding-left: 20px}
p, li {font-size:14px}
textarea {color: #000; font-size:13px}
</style>
<form action="index.php" method="post">
<table>
<tr><td>请输入句子</td>
<td><table style="border-collapse:collapse"><tr>
<td><textarea name="sentence" cols="45" rows="2">
<?php echo $sentence; ?>
</textarea></td>
<td style="padding-left:10px"><button type="submit" style="height:30px;font-size:20px">提交</button>
</td>
</tr></table>
</td>
<td class="comment" rowspan="5" style="vertical-align:top">候选句子列表：
<ul>
<li>I want the milk
<li>move to the table
<li>Allen gives me a drink
<li>Allen would like some tea
<li>if you run the empty oven it will be broken
<li>the man puts the bird in the house
<li>the man likes the bird in the house (注意与上句的区别)
</ul>
<hr />
Features:
<ol>
<li>语法规则允许左递归，不会陷入死循环。
<li>添加了若干语法元素以更真实地反映英语语法。(参见配置文件)
<li>各配置文件均可在线修改。
<li>句子中的标点符号会被自动忽略。
<li>非法语法规则和单词会被自动忽略，并在下面的console中warning。
</ol>
Notes:
<ol>
<li>服务器不会保存所填信息，重新载入页面时恢复默认值。
<li>每个单词只能有一种词性 (限于输入格式，算法无此限制)
<li>单词不区分大小写（全部预处理为小写），只能包含字母。
<li>本页面只在Chrome浏览器上测试过，不保证在旧版浏览器上的显示效果。
</ol>
<hr />
<a href="showsource.php" target="_blank">点击这里</a> 查看语法分析器的PHP源码 (UTF-8编码)
<p>采用自底向上的动态规划算法。
</p>
<hr />
<p><textarea disabled="disabled" cols="55" rows="30">
<?php echo $console; ?>
</textarea></p>
</td>
</tr>
<tr><td>语法树</td>
<td><textarea disabled="disabled" cols="55" rows="20">
<?php echo $tree_str; ?>
</textarea></td></tr>
<tr><td>语法配置
<br /><a href="grammar.xml" target="_blank">grammar.xml</a>
</td><td><textarea name="grammar" cols="55" rows="20">
<?php echo $grammar; ?>
</textarea></td></tr>
<tr><td>字典配置
<br /><a href="lexicon.xml" target="_blank">lexicon.xml</a>
</td><td><textarea name="lexicon" cols="55" rows="15">
<?php echo $lexicon; ?>
</textarea></td></tr>
<tr><td>符号配置
<br /><a href="token.xml" target="_blank">token.xml</a>
</td><td><textarea name="tokens" cols="55" rows="10">
<?php echo $tokens; ?>
</textarea></td></tr>
<tr><td></td><td><button type="submit" style="height:30px;font-size:20px">提交</button></td></tr>
</table>
</form>
<hr />
<p>&copy; 2012 PB10000603 李博杰 (boj AT mail.ustc.edu.cn) 最后修改：2012年7月5日
<a href="http://gewu.ustc.edu.cn/boj/parser/index.php">Permalink</a></p>
</body>
</html>
