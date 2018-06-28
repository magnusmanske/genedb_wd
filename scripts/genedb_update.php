#!/usr/bin/php
<?PHP

# Data format: http://geneontology.org/page/go-annotation-file-format-20
# Last data file: ftp://ftp.sanger.ac.uk/pub/project/pathogens/malaria2/3D7/3D7.latest_version/version3.1/2016/December_2016/Gene_ontology/gene_association.Pfalciparum.1.12.2016
# Entrez file: http://plasmodb.org/common/downloads/Current_Release/Pfalciparum3D7/txt/PlasmoDB-29_Pfalciparum3D7_GeneAliases.txt

exit(0) ; // THIS SCRIPT IS SUPERSEDED BY sync_dump2wd.php

error_reporting(E_ERROR|E_CORE_ERROR|E_COMPILE_ERROR); // E_ALL|

require_once ( '/data/project/wikidata-todo/public_html/php/common.php' ) ;
require_once ( '/data/project/wikidata-todo/public_html/php/wikidata.php' ) ;
require_once ( '/data/project/sourcemd/sourcemd.php' ) ;
require_once ( '/data/project/quickstatements/public_html/quickstatements.php' ) ;

$mydir = "/data/project/genedb/data" ;
$datafile = "$mydir/gene_association.Pfalciparum.1.5.2018" ;
$entrezfile = "$mydir/entrez" ;

$evidence = array (
	'EXP' => 'Q23173789' ,
	'IDA' => 'Q23174122' ,
	'IPI' => 'Q23174389' ,
	'IMP' => 'Q23174671' ,
	'IGI' => 'Q23174952' ,
	'IEP' => 'Q23175251' ,
	'ISS' => 'Q23175558' ,
	'ISO' => 'Q23190637' ,
	'ISA' => 'Q23190738' ,
	'ISM' => 'Q23190825' ,
	'IGC' => 'Q23190826' ,
	'IBA' => 'Q23190827' ,
	'IBD' => 'Q23190833' ,
	'IKR' => 'Q23190842' ,
	'IRD' => 'Q23190850' ,
	'RCA' => 'Q23190852' ,
	'TAS' => 'Q23190853' ,
	'NAS' => 'Q23190854' ,
	'IC' => 'Q23190856' ,
	'ND' => 'Q23190857' ,
	'IEA' => 'Q23190881'
) ;

$aspects = array (
	'P' => 'P682' ,
	'F' => 'P680' ,
	'C' => 'P681'
) ;

$qs = new QuickStatements ;
$qs->use_oauth = false ;
$qs->bot_config_file = '/data/project/wikidata-todo/reinheitsgebot.conf' ;

function out ( $arr ) {
	global $qs ;
	$s = implode ( "\t" , $arr ) . "\n" ;
	$commands = $qs->importData ( $s , 'v1' ) ;
	$commands = json_decode ( json_encode ( $commands ) ) ;
	foreach ( $commands->data->commands AS $c ) {
		$res = $qs->runSingleCommand ( $c ) ;
		if ( $res->status == 'done' ) continue ;
		if ( isset($res->message) and preg_match ( '/^The statement has already a reference with hash /' , $res->message  ) ) continue ; // Not really an error
		print "ERROR\n" ; print_r ( $res ) ;
	}
}


$pmid_cache = array() ;
function appendPaperFromPMID ( $pmid , &$arr ) {
	global $pmid_cache , $mydir ;
	
	// Already in cache?
	if ( isset($pmid_cache[$pmid]) ) {
		$q = $pmid_cache[$pmid] ;
		$arr[] = 'S248' ;
		$arr[] = $q ;
		return true ;
	}
	
	// Try SPARQL
	$sparql = "SELECT ?q { ?q wdt:P698 \"$pmid\" }" ;
	$items = getSPARQLitems ( $sparql ) ;
	if ( count($items) == 1 ) {
		$pmid_cache[$pmid] = 'Q' . $items[0] ;
		return appendPaperFromPMID ( $pmid , $arr ) ;
	}

	// Try to create
	$smd = new SourceMD ( $pmid , false ) ;
	if ( isset($smd->existing_q) ) {
		$q = $smd->existing_q ;
		$q = 'Q' . preg_replace ( '/\D/' , '' , $q ) ;
		$pmid_cache[$pmid] = $q ;
		return appendPaperFromPMID ( $pmid , $arr ) ;
	}
	$qs = $smd->generateQuickStatements() ;
	$tmp = "$mydir/tmp.qs" ;
	file_put_contents ( $tmp , implode("\n",$qs) ) ;
	exec ( "/data/project/wikidata-todo/scripts/quick_statements.php $tmp" , $out ) ;
	$q = trim ( implode ( ' ' , $out ) ) ;
	if ( preg_match ( '/^Q\d+$/' , $q ) ) {
		$pmid_cache[$pmid] = $q ;
		appendPaperFromPMID ( $pmid , $arr ) ;
		return true ;
	}
	
	// Fallback
	$arr[] = 'S698' ;
	$arr[] = '"' . $pmid . '"' ;
	return true ;
}

function syncItemEntry ( $i , $e ) {
	global $go2q , $evidence , $aspects , $gb2entrez ;

	$gbid = $e[0]['dbid'] ;

	// GeneDB IDs
	$genedb_ids = $i->getStrings('P3382') ;
	if ( !in_array ( $e[0]['dbid'] , $genedb_ids ) ) {
		$arr = array ( $i->getQ() , 'P3382' , '"' . $gbid . '"' ) ;
		out ( $arr ) ;
		$genedb_ids[] = $e[0]['dbid'] ;
	}
	
	// GO terms and sources
	foreach ( $e AS $v ) {
		$goid = trim(strtoupper($v['goid'])) ;
		if ( $goid == '' ) continue ;
		
		if ( !isset($go2q[$goid]) ) {
			print "NO GO ID FOR $goid IN WIKIDATA\n" ;
			continue ;
		}
		if ( !isset($aspects[$v['aspect']]) ) {
			print "NO Q FOR ASPECT {$v['aspect']} IN WIKIDATA\n" ;
			continue ;
		}
		$arr = array ( $i->getQ() , $aspects[$v['aspect']] , $go2q[$goid] ) ;
		
		$has_main_claim = false ;
		$has_source = false ;
		$has_evidence_qualifier = false ;
		
		$ac = $i->getClaims ( $arr[1] ) ; // Aspect claims
		foreach ( $ac AS $c ) {
			if ( $c->mainsnak->datavalue->value->id != $arr[2] ) continue ;
			$has_main_claim = true ;
			if ( isset($c->references) and count($c->references) > 0 ) $has_source = true ; // Rough, but...
			if ( isset($c->qualifiers) and isset($c->qualifiers->P459) ) $has_evidence_qualifier = true ;
		}

		if ( !$has_source ) {
			if ( preg_match ( '/^PMID:(.+)$/' , $v['dbref'] , $m ) ) {
				$pmid = $m[1] ;
				appendPaperFromPMID ( $pmid , $arr ) ;
				$date = $v['date'] ;
				if ( preg_match ( '/^(\d{4})(\d{2})(\d{2})$/' , $date , $m ) ) {
					$arr[] = 'S813' ;
					$arr[] = "+{$m[1]}-{$m[2]}-{$m[3]}T00:00:00Z/11" ;
				}
			}
		}
		
		if ( !$has_evidence_qualifier and isset ( $evidence[$v['evidence']] ) ) {
			$q = $evidence[$v['evidence']] ;
			$arr[] = 'P459' ;
			$arr[] = $q ;
		}

		if ( $has_main_claim and count($arr) == 3 ) continue ; // Statement already exists
		
		// Create new statement
		out ( $arr ) ;
	}

	// Entrez
	$gbid2 = preg_replace('/\..+$/','',$gbid) ;
	if ( isset($gb2entrez[$gbid2]) ) {
		$candidates = $i->getStrings('P351') ;
		if ( !in_array ( $gb2entrez[$gbid2] , $candidates ) ) {
			$d = date ( 'Y-m-d' ) ;
			$d = '+'.$d.'T00:00:00Z/11' ;
			$arr = array ( $i->getQ() , 'P351' , json_encode($gb2entrez[$gbid2]) , 'S143' , 'Q7201815' , 'S813' , $d ) ;
			// TODO?
//			print_r ( $arr ) ;
		}
	}
	
	// Aliases TODO
	
}

# Build internal data model
$cols = array ( 'db' , 'dbid' , 'dbos' , 'qual' , 'goid' , 'dbref' , 'evidence' , 'with' , 'aspect' , 'dboname' , 'dbosyn' , 'dbotype' , 'taxon' , 'date' , 'ass' , 'annext' , 'gpid' ) ;
$entries = array() ;
$handle = @fopen($datafile, "r");
while (($line = fgets($handle)) !== false) {
	if ( preg_match ( '/^!/' , $line ) ) continue ; # Header
	$d = explode ( "\t" , trim ( $line ) ) ;
	if ( count($d) < 3 ) continue ; # Paranoia
	if ( $d[0] != 'GeneDB_Pfalciparum' ) continue ; # Paranoia
	$id = preg_replace ( '/\.\d+$/' , '' , $d[1] ) ;
	$arr = array() ;
	foreach ( $d AS $k => $v ) $arr[$cols[$k]] = $v ;
	$entries[$id][] = $arr ;
}
fclose($handle);

# ENTREZ data
$gb2entrez = array() ;
$handle = @fopen($entrezfile, "r");
while (($line = fgets($handle)) !== false) {
	$d = explode ( "\t" , trim ( $line ) ) ;
	if ( count($d) < 3 ) continue ; # Paranoia
	if ( !isset($entries[$d[0]]) ) continue ; # Paranoia
	if ( !preg_match ( '/^\d+$/' , $d[1] ) ) continue ; # Numeric ENTREZ only
	$gb2entrez[$d[0]] = $d[1] ;
}
fclose($handle);



# Get gene item IDs from SPARQL
$sparql = "SELECT ?q { ?q wdt:P279 wd:Q7187 . ?q wdt:P703 wd:Q311383 . ?q wdt:P3382 [] }" ;
$items = getSPARQLitems ( $sparql ) ;
$wil = new WikidataItemList ;
$wil->loadItems ( $items ) ;

# Get GO terms
$sparql = "SELECT ?q ?go { ?q wdt:P686 ?go }" ;
$j = getSPARQL ( $sparql ) ;
$go2q = array() ;
foreach ( $j->results->bindings AS $v ) {
//	if ( isset($go2q[$v->go->value]) ) print "DOUBLE GO ID FOR {$v->go->value}\n" ;
	$go2q[$v->go->value] = preg_replace ( '/^.+\/Q/' , 'Q' , $v->q->value ) ;
}

# Map entries to items
foreach ( $items AS $q ) {
	$q = "Q$q" ;
//if ( $q != 'Q19043906' ) continue ; // TESTING single item
	$i = $wil->getItem ( $q ) ;
	if ( !isset($i) ) continue ;
	$l = $i->getStrings ( 'P3382' ) ;
	$l = $l[0] ;
//	$l = $i->getLabel ( 'en' , true ) ;
	if ( !isset($l) or $l == '' ) continue ;
	$l2 = preg_replace ( '/\..+$/' , '' , $l ) ;
	if ( !isset($entries[$l]) and !isset($entries[$l2]) ) { // On Wikidata but not in GeneDB list
		if ( preg_match ( '/^PF3D7_\d{7}$/' , $l ) ) {
			$arr = array ( $q , 'P3382' , '"' . $l . '"' ) ;
			// TODO?
//			print_r ( $arr ) ;
		}
		continue ;
	}
	if ( !isset($entries[$l]) ) $l = $l2 ;
	$e = $entries[$l] ;
	syncItemEntry ( $i , $e ) ;
}

?>