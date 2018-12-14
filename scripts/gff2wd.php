#!/usr/bin/php
<?php

require_once ( "/data/project/genedb/scripts/GFF.php" ) ;
require_once ( '/data/project/genedb/public_html/php/wikidata.php' ) ;
require_once ( '/data/project/genedb/public_html/php/ToolforgeCommon.php' ) ;
require_once ( '/data/project/quickstatements/public_html/quickstatements.php' ) ;
require_once ( '/data/project/sourcemd/scripts/orcid_shared.php' ) ;

$qs = '' ;

class GFF2WD {
	var $tfc , $wil ;
	var $gffj ;
	var $go_annotation = [] ;
	var $genes = [] ;
	var $genedb2q = [] ;
	var $protein_genedb2q = [] ;
	var $orth_genedb2q = [] ;
	var $orth_genedb2q_taxon = [] ;
	public $qs ;
	var $go_term_cache ;
	var $aspects = [ 'P' => 'P682' , 'F' => 'P680' , 'C' => 'P681' ] ;
	var $evidence_codes = [] ;
	var $sparql_result_cache = [] ;

	function GFF2WD () {
		global $qs ;
		$this->tfc = new ToolforgeCommon ( 'genedb' ) ;
		$this->qs = $this->tfc->getQS ( 'genedb' , '/data/project/genedb/bot.ini' , true ) ;
		$qs = $this->qs ;
		$this->wil = new WikidataItemList () ;
		$this->dbw = $this->tfc->openDB ( 'wikidata' , 'wikidata' ) ;
	}

	function init () {
		$this->loadBasicItems() ;
		$this->loadGAF() ;
		$this->loadGFF() ;
		$this->run() ;
	}

	function getQforChromosome ( $chr ) {
		if ( isset($this->gffj->chr2q[$chr]) ) return $this->gffj->chr2q[$chr] ;

		$commands = [] ;
		$commands[] = 'CREATE' ;
		$commands[] = "LAST\tLen\t\"{$chr}\"" ;
		$commands[] = "LAST\tP31\tQ37748" ; # Chromosome
		$commands[] = "LAST\tP703\t{$this->gffj->species}" ;
		$q = $this->tfc->runCommandsQS ( $commands , $this->qs ) ;
		if ( !isset($q) or $q == '' ) die ( "Could not create item for chromosome '{$chr}'\n" ) ;
		$this->gffj->chr2q[$chr] = $q ;
		return $q ;
	}

	function loadBasicItems () {
		# Load basic items (species, chromosomes)
		$items = $this->tfc->getSPARQLitems ( "SELECT ?q { ?q wdt:P31 wd:Q37748 ; wdt:P703 wd:{$this->gffj->species} }" ) ;
		$items[] = $this->gffj->species ;
		$this->wil->loadItems ( $items ) ;
		$this->gffj->chr2q = [] ;
		foreach ( $items AS $q ) {
			$i = $this->wil->getItem ( $q ) ;
			if ( !isset($i) ) continue ;
			if ( !$i->hasTarget ( 'P31' , 'Q37748' ) ) continue ;
			$l = $i->getLabel ( 'en' , true ) ;
			$this->gffj->chr2q[$l] = $q ;
		}

		# All genes for this species with GeneDB ID
		$sparql = "SELECT ?q ?genedb { ?q wdt:P31 wd:Q7187 ; wdt:P703 wd:{$this->gffj->species} ; wdt:P3382 ?genedb }" ;
		$j = $this->tfc->getSPARQL ( $sparql ) ;
//		if ( !isset($j->results) or !isset($j->results->bindings) or count($j->results->bindings) == 0 ) die ( "SPARQL loading of genes failed\n" ) ;
		foreach ( $j->results->bindings AS $v ) {
			$q = $this->tfc->parseItemFromURL ( $v->q->value ) ;
			if ( !$this->isRealItem($q) ) continue ;
			$genedb_id = $v->genedb->value ;
			if ( isset($this->genedb2q[$genedb_id]) ) die ( "Double genedb {$genedb_id} for gene {$q} and {$this->genedb2q[$genedb_id]}\n" ) ;
			$this->genedb2q[$genedb_id] = $q ;
		}

		# All protein for this species with GeneDB ID
		$j = $this->tfc->getSPARQL ( "SELECT ?q ?genedb { ?q wdt:P31 wd:Q8054 ; wdt:P703 wd:{$this->gffj->species} ; wdt:P3382 ?genedb }" ) ;
//		if ( !isset($j->results) or !isset($j->results->bindings) ) die ( "SPARQL loading of proteins failed\n" ) ;
		foreach ( $j->results->bindings AS $v ) {
			$q = $this->tfc->parseItemFromURL ( $v->q->value ) ;
			if ( !$this->isRealItem($q) ) continue ;
			$genedb_id = $v->genedb->value ;
			if ( isset($this->protein_genedb2q[$genedb_id]) ) die ( "Double genedb {$genedb_id} for protein {$q} and {$this->protein_genedb2q[$genedb_id]}\n" ) ;
			$this->protein_genedb2q[$genedb_id] = $q ;
		}

		# Evidence codes
		$sparql = 'SELECT ?q ?qLabel { ?q wdt:P31 wd:Q23173209 SERVICE wikibase:label { bd:serviceParam wikibase:language "en" } }' ;
		$j = $this->tfc->getSPARQL ( $sparql ) ;
		foreach ( $j->results->bindings AS $b ) {
			$label = $b->qLabel->value ;
			$eq = $this->tfc->parseItemFromURL ( $b->q->value ) ;
			$this->evidence_codes[$label] = $eq ;
		}

		$to_load = array_merge (
			array_values($this->genedb2q),
			array_values($this->protein_genedb2q),
			array_values($this->evidence_codes)
		) ;
		$this->wil->loadItems ( $to_load ) ; # TODO turn on
	}

	function isRealItem ( $q ) { # Returns false if it's a redirect
	return true ; # SHORTCUTTING
		$q = $this->dbw->real_escape_string ( $q ) ;
		$sql = "SELECT * FROM page WHERE page_namespace=0 AND page_is_redirect=0 AND page_title='{$q}'" ;
		$result = $this->tfc->getSQL ( $this->dbw , $sql ) ;
		if($row = $result->fetch_assoc()) return true ;
		return false ;
	}

	function loadGAF () {
		$gaf_filename = '/data/project/genedb/data/gaf/'.$this->gffj->file_root.'.gaf' ;
		if ( !file_exists($gaf_filename) ) die ( "No GAF file: {$gaf_filename}\n") ;
		$gaf = new GAF ( $gaf_filename ) ;
		while ( $r = $gaf->nextEntry() ) {
			if ( isset($r['header'] ) ) continue ;
			$this->go_annotation[$r['id']][] = $r ;
		}
	}

	function loadGFF () {
		$gff_filename = '/data/project/genedb/data/gff/'.$this->gffj->file_root.'.gff3.gz' ;
		if ( !file_exists($gff_filename) ) die ( "No GFF file: {$gff_filename}\n") ;
		$gff = new GFF ( $gff_filename ) ;
		$orth_ids = [] ;
		while ( $r = $gff->nextEntry() ) {
			if ( isset($r['comment']) ) continue ;
			if ( !in_array ( $r['type'] , ['gene','mRNA'] ) ) continue ;
#			if ( !isset($this->gffj->chr2q[$r['seqid']]) ) continue ; # Paranoia
			if ( isset($r['attributes']['Parent']) ) $this->genes[$r['attributes']['Parent']][$r['type']][] = $r ;
			else $this->genes[$r['attributes']['ID']]['gene'] = $r ;
			if ( isset($r['attributes']['orthologous_to']) ) {
				foreach ( $r['attributes']['orthologous_to'] AS $orth ) {
					if ( !preg_match ( '/^\S+?:(\S+)/' , $orth , $m ) ) continue ;
					$orth_ids[$m[1]] = 1 ;
				}
			}
		}

		# Orthologs cache
		$orth_chunks = array_chunk ( array_keys($orth_ids) , 100 ) ;
		foreach ( $orth_chunks AS $chunk ) {
			$j = $this->tfc->getSPARQL ( "SELECT ?q ?genedb ?taxon { VALUES ?genedb { '" . implode("' '",$chunk) . "' } . ?q wdt:P3382 ?genedb ; wdt:P703 ?taxon }" ) ;
			foreach ( $j->results->bindings AS $v ) {
				$q = $this->tfc->parseItemFromURL ( $v->q->value ) ;
				$q_taxon = $this->tfc->parseItemFromURL ( $v->taxon->value ) ;
				$genedb = $v->genedb->value ;
				$this->orth_genedb2q[$genedb] = $q ;
				$this->orth_genedb2q_taxon[$genedb] = $q_taxon ;
			}
		}
#		print_r ( $orth_ids ) ; exit(0);

	}

	function createOrAmendGeneItem ( $g ) {
		if ( !isset($g['gene']) ) die ( "No attributes for gene\n".json_encode($g)."\n" ) ;
		$gene = $g['gene'] ;
		if ( !isset($gene['attributes']) ) die ( "No attributes for gene\n".json_encode($g)."\n" ) ;
		$genedb_id = $gene['attributes']['ID'] ;

#		if ( !isset($this->gffj->chr2q[$gene['seqid']]) ) die ( "Chromosome {$gene['seqid']} for {$genedb_id} not found\n" ) ;
		$chr_q = $this->getQforChromosome ( $gene['seqid'] ) ; #$this->gffj->chr2q[$gene['seqid']] ;

		$commands = [] ;
		if ( isset($this->genedb2q[$genedb_id]) ) {
			$gene_q = $this->genedb2q[$genedb_id] ;
			$this->wil->loadItems ( [$gene_q] ) ;
			$gene_i = $this->wil->getItem ( $gene_q ) ;
		} else {
			$commands[] = 'CREATE' ;
			$gene_q = 'LAST' ;
			$gene_i = new WDI ;
			$gene_i->q = $gene_q ;
			$gene_i->j = json_decode ( '{}' ) ;
		}
#print "{$genedb_id} : {$gene_q}\n" ;
#print_r ( $g ) ;
		# Label and aliases, en only
		if ( $gene_i->getLabel('en',true) == $gene_q ) $commands[] = "{$gene_q}\tLen\t\"{$genedb_id}\"" ;
		$should_have_aliases = [] ;
		if ( isset($gene['attributes']['previous_systematic_id']) ) {
			foreach ( $gene['attributes']['previous_systematic_id'] AS $v ) $should_have_aliases[$v] = 1 ;
		}
		if ( isset($gene['attributes']['Name']) ) $should_have_aliases[$gene['attributes']['Name']] = 1 ;
		if ( isset($gene['attributes']['synonym']) ) $should_have_aliases[$gene['attributes']['synonym']] = 1 ;
		$existing_aliases = $gene_i->getAliases('en') ;
		foreach ( $should_have_aliases AS $alias => $dummy ) {
			if ( !in_array ( $alias , $existing_aliases) ) $commands[] = "{$gene_q}\tAen\t\"{$alias}\"" ;
		}

		if ( !$gene_i->hasClaims('P31') ) $commands[] = "{$gene_q}\tP31\tQ7187" ; # Instance of:gene
		if ( !$gene_i->hasClaims('P279') ) $commands[] = "{$gene_q}\tP279\tQ20747295" ; # Subclass of:protein-coding gene
		if ( !$gene_i->hasClaims('P3382') ) $commands[] = "{$gene_q}\tP3382\t\"{$genedb_id}\"" ; # GeneDB ID
		if ( !$gene_i->hasClaims('P703') ) $commands[] = "{$gene_q}\tP703\t{$this->gffj->species}" ; # Found in:Species
		if ( !$gene_i->hasClaims('P1057') ) $commands[] = "{$gene_q}\tP1057\t{$chr_q}" ; # Chromosome
		if ( !$gene_i->hasClaims('P644') ) $commands[] = "{$gene_q}\tP644\t\"{$gene['start']}\"\tP659\t{$this->gffj->genomic_assembly}\tP1057\t{$chr_q}" ; # Genomic start
		if ( !$gene_i->hasClaims('P645') ) $commands[] = "{$gene_q}\tP645\t\"{$gene['end']}\"\tP659\t{$this->gffj->genomic_assembly}\tP1057\t{$chr_q}" ; # Genomic end
		if ( !$gene_i->hasClaims('P2548') ) { # Strand
			$strand_q = $gene['strand'] == '+' ? 'Q22809680' : 'Q22809711' ;
			$commands[] = "{$gene_q}\tP2548\t{$strand_q}\tP659\t{$this->gffj->genomic_assembly}\tP1057\t{$chr_q}" ; # Genomic end
		}


		# Do protein
		$protein_q = $this->createOrAmendProteinItem ( $g , $gene_q ) ;
		if ( isset($protein_q) and $protein_q != '' and $protein_q != 'Q' and count($g['mRNA']) == 1 and !$gene_i->hasClaims('P688') ) { # Encodes
			$commands[] = "{$gene_q}\tP688\t{$protein_q}" ; # Encodes:Protein
		}

		# Orthologs
		if ( isset($protein_q) and count($g['mRNA']) == 1 and isset($g['mRNA'][0]['attributes']['orthologous_to']) ) {
			foreach ( $g['mRNA'][0]['attributes']['orthologous_to'] AS $orth ) {
				if ( !preg_match ( '/^(\S)+?:(\S+)/' , $orth , $m ) ) continue ;
				$species = $m[1] ;
				$genedb_orth = $m[2] ;
				if ( !isset($this->orth_genedb2q[$genedb_orth]) ) continue ;
				if ( !isset($this->orth_genedb2q_taxon[$genedb_orth]) ) continue ;
				$orth_q = $this->orth_genedb2q[$genedb_orth] ;
				if ( $gene_i->hasTarget('P684',$orth_q) ) continue ;
				$orth_q_taxon = $this->orth_genedb2q_taxon[$genedb_orth] ;
				$cmd = "{$gene_q}\tP684\t{$orth_q}\tP703\t{$orth_q_taxon}" ;
				$commands[] = $cmd ;
			}
		}


		# Remove GO codes from gene (should be in protein)
		foreach ( ['P680','P681','P682'] AS $prop ) {
			$claims = $gene_i->getClaims ( $prop ) ;
			foreach ( $claims AS $c ) {
				$target = $gene_i->getTarget ( $c ) ;
				$commands[] = "-{$gene_q}\t{$prop}\t{$target}/*Now in protein*/" ;
			}
		}


		$this->addSourceToCommands ( $commands ) ;
#print_r ( $commands ) ;
		$new_gene_q = $this->tfc->runCommandsQS ( $commands , $this->qs ) ;
		if ( $gene_q == 'LAST' and isset($protein_q) and $new_gene_q !== null ) {
			$commands = [ "{$protein_q}\tP702\t{$new_gene_q}" ] ; # Protein:encoded by:gene
			$this->addSourceToCommands ( $commands ) ;
#print_r ( $commands ) ;
			$this->tfc->runCommandsQS ( $commands , $this->qs ) ;
		}
	}

	function createOrAmendProteinItem ( $g , $gene_q ) {
		if ( !isset($g['mRNA']) ) {
			print ( "No attributes for mRNA\n".json_encode($g)."\n" ) ;
			return ;
		}
		$protein = $g['mRNA'][0] ;
		if ( !isset($protein['attributes']) ) die ( "No attributes for protein\n".json_encode($g)."\n" ) ;
		$genedb_id = $protein['attributes']['ID'] ;
#print "PROTEIN ID:{$genedb_id}\n" ;
#print_r($this->protein_genedb2q);
		$commands = [] ;
		if ( isset($this->protein_genedb2q[$genedb_id]) ) {
			$protein_q = $this->protein_genedb2q[$genedb_id] ;
			$this->wil->loadItems ( [$protein_q] ) ;
			$protein_i = $this->wil->getItem ( $protein_q ) ;
		} else {
			$commands[] = 'CREATE' ;
			$protein_q = 'LAST' ;
			$protein_i = new WDI ;
			$protein_i->q = $protein_q ;
			$protein_i->j = json_decode ( '{}' ) ;
		}
#if($protein_q=='LAST'){print_r ( $commands ) ; print "{$gene_q}/{$genedb_id}\n" ; exit(0) ;}

		$label = $genedb_id ;
		$desc = '' ;
		$should_have_aliases = [] ;
		$literature = [] ;

		if ( isset($protein['attributes']['literature']) ) {
			foreach ( $protein['attributes']['literature'] AS $lit_id ) $literature[$lit_id] = 1 ;
		}

		if ( !$protein_i->hasClaims('P31') ) $commands[] = "{$protein_q}\tP31\tQ8054" ; # Instance of:protein
		if ( !$protein_i->hasClaims('P279') ) $commands[] = "{$protein_q}\tP279\tQ8054" ; # Instance of:protein
		if ( !$protein_i->hasClaims('P703') ) $commands[] = "{$protein_q}\tP703\t{$this->gffj->species}" ; # Found in:Species
		if ( $gene_q != 'LAST' and !$protein_i->hasClaims('P702') ) $commands[] = "{$protein_q}\tP702\t{$gene_q}" ; # Encoded by:gene
		if ( !$protein_i->hasClaims('P3382') ) $commands[] = "{$protein_q}\tP3382\t\"{$genedb_id}\"" ; # GeneDB ID

		$xref2prop = [
#			'MPMP' => '???' ,
			'UniProtKB' => 'P352'
		] ;

		if ( isset($protein['attributes']) and isset($protein['attributes']['Dbxref']) ) {
			foreach ( $protein['attributes']['Dbxref'] AS $xref ) {
				$xref = explode ( ':' , $xref , 2 ) ;
				$key = trim($xref[0]) ;
				$value = trim($xref[1]) ;
				if ( !isset($xref2prop[$key]) ) continue ;
				$prop = $xref2prop[$key] ;
				if ( $protein_i->hasClaims($prop) ) continue ;
				$commands[] = "{$protein_q}\t{$prop}\t\"{$value}\"" ;
			}
		}

		if ( isset($this->go_annotation[$genedb_id]) ) {
			$goann = $this->go_annotation[$genedb_id] ;
#print_r ( $goann ) ;
			foreach ( $goann AS $ga ) {
				$go_q = $this->getItemForGoTerm ( $ga['go'] ) ;
				if ( !isset($go_q) ) continue ;
				if ( !isset($this->aspects[$ga['aspect']]) ) continue ;
				$aspect_p = $this->aspects[$ga['aspect']] ;
				if ( $protein_i->hasTarget($aspect_p,$go_q) ) continue ;
				if ( !isset($this->evidence_codes[$ga['evidence_code']]) ) continue ;
				$evidence_code_q = $this->evidence_codes[$ga['evidence_code']] ;

				$lit_source = '' ;
				$lit_id = $ga['db_ref'] ;
				if ( $lit_id == 'WORKSHOP' ) continue ; // Huh
				$lit_q = $this->getOrCreatePaperFromID ( $lit_id ) ;
				if ( isset($lit_q) ) {
					$lit_source = "S248\t{$lit_q}" ;
				} else {
					if ( preg_match ( '/^InterPro:(.+)$/' , $ga['with_from'] , $m ) ) {
						$lit_source = "S2926\t\"{$m[1]}\"" ;
					}
				}
				if ( $lit_source == '' ) continue ; # Paranoia

				$commands[] = "{$protein_q}\t{$aspect_p}\t{$go_q}\tP459\t{$evidence_code_q}\t$lit_source\tS1640\tQ5531047" ; # \tS459\t{$evidence_code_q}
				$literature[$lit_id] = 1 ;

				if ( isset($ga['name']) and $label == $genedb_id ) {
					$label = $ga['name'] ;
					$should_have_aliases[$genedb_id] = 1 ;
				}
				foreach ( $ga['synonym'] AS $alias ) $should_have_aliases[$alias] = 1 ;
			}
		}

		if ( $protein_i->getLabel('en',true) == $protein_q ) $commands[] = "{$protein_q}\tLen\t\"{$label}\"" ;
		if ( !$protein_i->hasDescriptionInLanguage('en') and $desc!='' ) $commands[] = "{$protein_q}\tDen\t\"{$desc}\"" ;
		$existing_aliases = $protein_i->getAliases('en') ;
		foreach ( $should_have_aliases AS $alias => $dummy ) {
			if ( trim($alias) == '' ) continue ;
			if ( !in_array ( $alias , $existing_aliases) ) $commands[] = "{$protein_q}\tAen\t\"{$alias}\"" ;
		}

		$this->addSourceToCommands ( $commands ) ;
#if ( count($commands) > 0 ) print_r ( $commands ) ;
		
		$new_protein_q = $this->tfc->runCommandsQS ( $commands , $this->qs ) ;
		if ( $protein_q == 'LAST' ) {
			$this->protein_genedb2q[$genedb_id] = $new_protein_q ;
			$protein_q = $new_protein_q ;
		}


		# attributes:literature "main subject"
		$commands = [] ;
		foreach ( $literature AS $lit_id => $dummy ) {
			$lit_q = $this->getOrCreatePaperFromID ( $lit_id ) ;
			if ( !isset($lit_q) ) continue ; # Something's wrong
			$this->wil->loadItems ( [$lit_q] ) ;
			$lit_i = $this->wil->getItem ( $lit_q ) ;
			if ( !isset($lit_i) ) continue ;
			if ( $lit_i->hasTarget('P921',$protein_q) ) continue ;
			if ( !isset($protein_q) or $protein_q=='' or $protein_q=='Q' ) continue ;
			$commands[] = "{$lit_q}\tP921\t{$protein_q}" ;
		}
		$this->addSourceToCommands ( $commands ) ;
#print_r ( $commands ) ;
		$this->tfc->runCommandsQS ( $commands , $this->qs ) ;
#print "PROTEIN: {$protein_q}\n" ;
		return $protein_q ;
	}

	function getItemForGoTerm ( $go_term ) {
		if ( isset($this->go_term_cache[$go_term]) ) return $this->go_term_cache[$go_term] ;
		$sparql = "SELECT ?q { ?q wdt:P686 '{$go_term}' }" ;
		$items = $this->tfc->getSPARQLitems ( $sparql ) ;
		if ( count($items) != 1 ) return ;
		$ret = $items[0] ;
		$this->go_term_cache[$go_term] = $ret ;
		return $ret ;
	}

	private function getAndCacheItemForSPARQL ( $sparql ) {
		$items = [] ;
		if ( isset($this->sparql_result_cache[$sparql]) ) {
			$items = $this->sparql_result_cache[$sparql] ;
		} else {
			$items = $this->tfc->getSPARQLitems ( $sparql ) ;
			$this->sparql_result_cache[$sparql] = $items ;
		}
		if ( count($items) == 1 ) {
			$q = $items[0] ;
			return $q ;
		}
//		if ( count($items) == 0 ) $this->log ( [ 'no_item_for_'.$what , $go ] ) ;
//		else $this->log ( [ 'multiple_item_for_'.$what , $go ] ) ;
	}

	function getOrCreatePaperFromID ( $lit_id ) {
		if ( !preg_match ( '/^(.+?):(.+)$/' , $lit_id , $m ) ) return ;
		if ( $m[1] == 'PMID' ) $prop = 'P698' ;
		else return ;
		$lit_id = $m[2] ;
		$ref_q = $this->getAndCacheItemForSPARQL ( "SELECT ?q { ?q wdt:{$prop} '{$lit_id}' }" , $prop ) ;
		if ( isset($ref_q) ) return $ref_q ;
#		print "TRYING TO CREATE NEW PUBLICATION FOR {$prop}:'{$lit_id}' => " ;
		if ( $prop == 'P698' ) $ref_q = getOrCreateWorkFromIDs ( ['pmid'=>$lit_id] ) ;
#		print "https://www.wikidata.org/wiki/{$ref_q}\n" ;
		return $ref_q ;
	}

	function addSourceToCommands ( &$commands ) {
		$source_date = "\tS813\t+" . date('Y-m-d') . "T00:00:00Z/11" ;
		foreach ( $commands AS $cmd_num => $cmd ) {
			if ( !preg_match ( '/^(LAST|Q\d+)\tP\d+\t/' , $cmd ) ) continue ;
			if ( !preg_match ( '/\tS248\t/' , $cmd) ) $commands[$cmd_num] .= "\tS248\tQ5531047" ;
			$commands[$cmd_num] .= $source_date ;
		}
	}

	function run () {
#		$this->createOrAmendGeneItem ( $this->genes['PF3D7_0223500'] ) ; exit(0); # TESTING 'PF3D7_0100100' / 'PF3D7_0102300'
		foreach ( $this->genes AS $gene ) {
			$this->createOrAmendGeneItem ( $gene ) ;
		}
	}
} ;

if ( !isset($argv[1]) ) die ( "Species key required\n" ) ;
$sk = $argv[1] ;

$config = json_decode( ( file_get_contents ( '/data/project/genedb/scripts/config.json' ) ) ) ;

$gff2wd = new GFF2WD ;
$gff2wd->gffj = (object) $config->species->$sk ;
$gff2wd->init() ;


function getQS () {
	global $qs ;
	return $qs ;
}

?>