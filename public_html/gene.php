<?php

require_once ( 'php/ToolforgeCommon.php' ) ;

$tfc = new ToolforgeCommon ;
$gene = $_REQUEST['gene'] ;
$sparql = "SELECT ?q { ?q wdt:P3382 '{$gene}' }" ;
$items = $tfc->getSPARQLitems ( $sparql , 'q' ) ;
if ( count($items) == 0 ) {
	http_response_code(404);
	print "<h1>404</h1>" ;
	print "You have reached the end of the internet. You can go home now.\n" ;
	die("");
}

header('Location: ./#/gene/'.$gene);
exit(0);

?>