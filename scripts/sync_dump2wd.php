#!/usr/bin/php
<?PHP

# Data format: http://geneontology.org/page/go-annotation-file-format-20

error_reporting(E_ERROR|E_CORE_ERROR|E_ALL|E_COMPILE_ERROR);

require_once ( './shared.php' ) ;

function getQS () {
	$toolname = '' ; // Or fill this in manually
	$path = realpath(dirname(__FILE__)) ;
	$user = get_current_user() ;
	if ( $toolname != '' ) {}
	else if ( preg_match ( '/^tools\.(.+)$/' , $user , $m ) ) $toolname = $m[1] ;
	else if ( preg_match ( '/^\/data\/project\/([^\/]+)/' , $path , $m ) ) $toolname = $m[1] ;
	else if ( preg_match ( '/^\/mnt\/nfs\/[^\/]+\/([^\/]+)/' , $path , $m ) ) $toolname = $m[1] ;
	if ( $toolname == '' ) die ( "getQS(): Can't determine the toolname for $path\n" ) ;
	$qs = new QuickStatements() ;
	$qs->use_oauth = false ;
	$qs->bot_config_file = "/data/project/$toolname/bot.ini" ;
	$qs->toolname = 'GeneDB:Entry-sync' ;
	$qs->sleep = 1 ;
	return $qs ;
}


$ftp_path = 'ftp.sanger.ac.uk/pub/project/pathogens/malaria2/3D7/3D7.latest_version/version3.1/2018/May_2018' ;
$data_base_dir = '/data/project/genedb/data' ;


// Download files, if necessary
$data_dir = "{$data_base_dir}/{$ftp_path}" ;
if ( !file_exists($data_dir) ) {
	print "Downloading from Sanger FTP site ftp://{$ftp_path}\n" ;
	exec ( "cd {$data_base_dir} ; wget -q -r ftp://{$ftp_path}" ) ;
}

$genes = new Genes ;
$genes->logfile_name = './log.json' ;
$genes->loadEvidenceCodes () ;
$genes->loadTaxonData ( 'Q311383' ) ;
$genes->taxon_data->reference_q = 'Q18968099' ; // "Plasmodium falciparum reference genome V3"

if ( 0 ) { // Amalgamate data (use this in production!)
	$gaf_file = "{$data_dir}/Gene_ontology/gene_association.Pfalciparum.1.5.2018" ; # HARDCODED TODO FIXME
	$genes->importFromGAF ( $gaf_file ) ;
	foreach ( $genes->taxon_data->chromosomes AS $label => $cq ) {
		$genes->importFromEmbl ( "{$data_dir}/{$label}.embl.gz" , $label ) ;
	}
//	print serialize ( $genes->genes ) ; exit(0) ;
} else { // Shortcut for testing
	$genes->loadGenesFromPHP ( "{$data_base_dir}/genes.php_serialized" ) ;
}

#$genes->genes = [ 'PF3D7_1207300' => $genes->genes['PF3D7_1207300'] ] ; // ONE GENE, FOR TESTING

$genes->findWikidataItems() ;
$genes->getWikidataItems() ;

foreach ( $genes->genes AS $gene ) {
	$gene->syncToWikidata() ;
}

?>