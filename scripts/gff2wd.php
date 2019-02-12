#!/usr/bin/php
<?php

require_once ( "/data/project/genedb/scripts/GFF.php" ) ;
require_once ( "/data/project/magnustools/public_html/php/itemdiff.php" ) ;
require_once ( '/data/project/quickstatements/public_html/quickstatements.php' ) ;
require_once ( '/data/project/sourcemd/scripts/orcid_shared.php' ) ;

set_time_limit ( 60 * 1000 ) ; // Seconds

error_reporting(E_ERROR|E_CORE_ERROR|E_COMPILE_ERROR); # |E_ALL

$qs = '' ;

class GFF2WD {
	var $use_local_data_files = false ;
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

	function init ( $genedb_id = '' ) {
		$this->loadBasicItems() ;
		$this->loadGAF() ;
		$this->loadGFF() ;
		$this->run($genedb_id) ;
	}

	function computeFilenameGFF () { # TODO use FTP directly
		if ( $this->use_local_data_files ) {
			$gff_filename = '/data/project/genedb/data/gff/'.$this->gffj->file_root.'.gff3.gz' ;
			if ( !file_exists($gff_filename) ) die ( "No GFF file: {$gff_filename}\n") ;
			return $gff_filename ;
		} else {
			return "ftp://ftp.sanger.ac.uk/pub/genedb/apollo_releases/latest/" . $this->gffj->file_root.'.gff3.gz' ; ;
		}
	}

	function computeFilenameGAF () { # TODO use FTP directly
		if ( $this->use_local_data_files ) {
			$ftp_root = 'ftp.sanger.ac.uk/pub/genedb/releases/latest/' ;
			$gaf_filename = '/data/project/genedb/data/gaf/'.$ftp_root.'/'.$this->gffj->file_root.'/'.$this->gffj->file_root.'.gaf.gz' ;
			if ( !file_exists($gaf_filename) ) die ( "No GAF file: {$gaf_filename}\n") ;
			return $gaf_filename ;
		} else {
			return "ftp://ftp.sanger.ac.uk/pub/genedb/releases/latest/" . $this->gffj->file_root.'/'.$this->gffj->file_root.'.gaf.gz' ;
		}
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
		$gaf_filename = $this->computeFilenameGAF() ;
		$gaf = new GAF ( $gaf_filename ) ;
		while ( $r = $gaf->nextEntry() ) {
			if ( isset($r['header'] ) ) continue ;
			$this->go_annotation[$r['id']][] = $r ;
		}
	}

	function loadGFF () {
		$gff_filename = $this->computeFilenameGFF() ;
		$gff = new GFF ( $gff_filename ) ;
		$orth_ids = [] ;
		while ( $r = $gff->nextEntry() ) {
			if ( isset($r['comment']) ) continue ;
			if ( !in_array ( $r['type'] , ['gene','mRNA'] ) ) continue ;
			if ( isset($r['attributes']['Parent']) ) $this->genes[$r['attributes']['Parent']][$r['type']][] = $r ;
			else $this->genes[$r['attributes']['ID']]['gene'] = $r ;
			if ( isset($r['attributes']['orthologous_to']) ) {
				foreach ( $r['attributes']['orthologous_to'] AS $orth ) {
					if ( !preg_match ( '/^\s*\S+?:(\S+)/' , $orth , $m ) ) continue ;
#if ( preg_match ( '/PRELSG_1210800/' , $orth ) ) print "!{$orth}!\n" ;
					$orth_ids[$m[1]] = 1 ;
				}
			}
		}

		# Orthologs cache
		$orth_chunks = array_chunk ( array_keys($orth_ids) , 100 ) ;
		foreach ( $orth_chunks AS $chunk ) {
			$sparql = "SELECT ?q ?genedb ?taxon { VALUES ?genedb { '" . implode("' '",$chunk) . "' } . ?q wdt:P3382 ?genedb ; wdt:P703 ?taxon }" ;
			$j = $this->tfc->getSPARQL ( $sparql ) ;
#if ( in_array('PRELSG_1210800',$chunk) ) print_r($j);
			foreach ( $j->results->bindings AS $v ) {
				$q = $this->tfc->parseItemFromURL ( $v->q->value ) ;
				$q_taxon = $this->tfc->parseItemFromURL ( $v->taxon->value ) ;
				$genedb = $v->genedb->value ;
#if ( $genedb == 'PRELSG_1210800' ) print "{$genedb}\t{$q}\t{$q_taxon}\n" ;
				$this->orth_genedb2q[$genedb] = $q ;
				$this->orth_genedb2q_taxon[$genedb] = $q_taxon ;
			}
		}

	}

	public function createOrAmendGeneItem ( $g ) {
		if ( !isset($g['gene']) ) {
			print "No gene:\n".json_encode($g)."\n" ;
			return ;
		}
		$gene = $g['gene'] ;
		if ( !isset($gene['attributes']) ) {
			print "No attributes for gene\n".json_encode($g)."\n" ;
			return ;
		}
		$genedb_id = $gene['attributes']['ID'] ;

		if ( isset($this->genedb2q[$genedb_id]) ) {
			$gene_q = $this->genedb2q[$genedb_id] ;
			$this->wil->loadItems ( [$gene_q] ) ;
			$item_to_diff = $this->wil->getItem ( $gene_q ) ;
		} else {
			$item_to_diff = new BlankWikidataItem ;
			$gene_q = 'LAST' ;
		}

		$chr_q = $this->getQforChromosome ( $gene['seqid'] ) ; #$this->gffj->chr2q[$gene['seqid']] ;
		$gene_i = new BlankWikidataItem ;

		# Label and aliases, en only

		if ( isset($gene['attributes']['Name']) ) {
			$gene_i->addLabel ( 'en' , $gene['attributes']['Name'] ) ;
			$gene_i->addAlias ( 'en' , $genedb_id ) ;
		} else {
			$gene_i->addLabel ( 'en' , $genedb_id ) ;
		}

		if ( isset($gene['attributes']['previous_systematic_id']) ) {
			foreach ( $gene['attributes']['previous_systematic_id'] AS $v ) $gene_i->addAlias ( 'en' , $v ) ;
		}
		if ( isset($gene['attributes']['synonym']) ) $gene_i->addAlias ( 'en' , $gene['attributes']['synonym'] ) ;

		# Statements
		$refs = [
			$gene_i->newSnak ( 'P248' , $gene_i->newItem('Q5531047') ) ,
			$gene_i->newSnak ( 'P813' , $gene_i->today() )
		] ;
		$ga_quals = [
			$gene_i->newSnak ( 'P659' , $gene_i->newItem($this->gffj->genomic_assembly) ) ,
			$gene_i->newSnak ( 'P1057' , $gene_i->newItem($chr_q) )
		] ;
		$strand_q = $gene['strand'] == '+' ? 'Q22809680' : 'Q22809711' ;

		$gene_i->addClaim ( $gene_i->newClaim('P31',$gene_i->newItem('Q7187') , [$refs] ) ) ; # Instance of:gene
		$gene_i->addClaim ( $gene_i->newClaim('P279',$gene_i->newItem('Q20747295') , [$refs] ) ) ; # Subclass of:protein-coding gene
		$gene_i->addClaim ( $gene_i->newClaim('P703',$gene_i->newItem($this->gffj->species) , [$refs] ) ) ; # Found in:Species
		$gene_i->addClaim ( $gene_i->newClaim('P1057',$gene_i->newItem($chr_q) , [$refs] ) ) ; # Chromosome
		$gene_i->addClaim ( $gene_i->newClaim('P2548',$gene_i->newItem($strand_q) , [$refs] , $ga_quals ) ) ; # Strand

		$gene_i->addClaim ( $gene_i->newClaim('P3382',$gene_i->newString($genedb_id) , [$refs] ) ) ; # GeneDB ID
		$gene_i->addClaim ( $gene_i->newClaim('P644',$gene_i->newString($gene['start']) , [$refs] , $ga_quals ) ) ; # Genomic start
		$gene_i->addClaim ( $gene_i->newClaim('P645',$gene_i->newString($gene['end']) , [$refs] , $ga_quals ) ) ; # Genomic end
		

		# Do protein
		$protein_qs = $this->createOrAmendProteinItems ( $g , $gene_q ) ;
		if ( count($protein_qs) > 0 ) { # Encodes
			foreach ( $protein_qs AS $protein_q ) {
				$gene_i->addClaim ( $gene_i->newClaim('P688',$gene_i->newItem($protein_q) , [$refs] ) ) ; # Encodes:Protein
			}
		}

		# Orthologs
		if ( count($protein_qs) > 0 ) {
			foreach ( $g['mRNA'] AS $protein ) {
				if ( !isset($protein['attributes']['orthologous_to']) ) continue ;
				foreach ( $protein['attributes']['orthologous_to'] AS $orth ) {
					if ( !preg_match ( '/^\s*(\S)+?:(\S+)/' , $orth , $m ) ) continue ;
					$species = $m[1] ; # Not used
					$genedb_orth = $m[2] ;
					if ( !isset($this->orth_genedb2q[$genedb_orth]) ) continue ;
					if ( !isset($this->orth_genedb2q_taxon[$genedb_orth]) ) continue ;
					$orth_q = $this->orth_genedb2q[$genedb_orth] ;
					$orth_q_taxon = $this->orth_genedb2q_taxon[$genedb_orth] ;
					$gene_i->addClaim ( $gene_i->newClaim('P684',$gene_i->newItem($orth_q) , [$refs] , [ $gene_i->newSnak ( 'P703' , $gene_i->newItem($orth_q_taxon) ) ] ) ) ; # Encodes:Protein
				}
			}
		}


		$options = [
			'ref_skip_p'=>['P813'] ,
			'labels' => [ 'ignore_except'=>['en'] ] ,
			'descriptions' => [ 'ignore_except'=>['en'] ] ,
			'aliases' => [ 'ignore_except'=>['en'] ] ,
			'remove_only' => ['P680','P681','P682','P1057']
		] ;
		$diff = $gene_i->diffToItem ( $item_to_diff , $options ) ;

		$params = (object) [
			'action' => 'wbeditentity' ,
			'data' => json_encode($diff) ,
			'summary' => 'Syncing to GeneDB (V2)' ,
			'bot' => 1
		] ;
		if ( $gene_q == 'LAST' ) $params->new = 'item' ;
		else $params->id = $gene_q ;

		# Create or amend gene item
		$new_gene_q = '' ;
		if ( $params->data == '{}' ) { # No changes
			if ( $gene_q == 'LAST' ) die ( "Cannot create empty gene for gene {$genedb_id}\n" ) ; # Paranoia
		} else {
			if ( !$this->qs->runBotAction ( $params ) ) {
				print_r ( $params ) ;
				die ( "Failed trying to edit gene '{$genedb_id}': '{$oa->error}' / ".json_encode($qs->last_res)."\n" ) ;
			}
			if ( $gene_q == 'LAST' ) {
				$new_gene_q = $qs->last_res->entity->id ;
				$this->genedb2q[$genedb_id] = $new_gene_q ;
				$this->wil->updateItem ( $new_gene_q ) ; # Is new
			} else {
				$this->wil->updateItem ( $gene_q ) ; # Has changed
			}
		}

		if ( $gene_q == 'LAST' and $this->isItem($new_gene_q) ) $gene_q = $new_gene_q ;
		if ( !$this->isItem ( $gene_q ) ) return ; # Paranoia

		# Ensure gene <=> protein links
		$to_load = $protein_qs ;
		$to_load[] = $gene_q ;
		$this->wil->loadItems ( $to_load ) ;
		foreach ( $protein_qs AS $protein_q ) {
			$this->linkProteinToGene ( $gene_q , $protein_q ) ;
		}
	}

	function isItem ( $q ) {
		if ( !isset($q) or $q === false or $q == null ) return false ;
		return preg_match ( '/^Q\d+$/' , $q ) ;
	}

	function linkProteinToGene ( $gene_q , $protein_q ) {
		if ( !$this->isItem ( $gene_q ) ) return ; # Paranoia
		if ( !$this->isItem ( $protein_q ) ) return ; # Paranoia
		$this->wil->loadItems ( [ $gene_q , $protein_q ] ) ;
		$gene = $this->wil->getItem ( $gene_q ) ;
		$protein = $this->wil->getItem ( $protein_q ) ;
		if ( !isset($gene) or !isset($protein) ) return ; # Paranoia

		$commands = [] ;
		if ( !$gene->hasTarget ( 'P688' , $protein_q ) ) { # Link gene to protein
			$commands[] = "{$gene_q}\tP688\t{$protein_q}" ; # Gene:encodes:Protein
		}

		if ( !$protein->hasTarget ( 'P702' , $gene_q ) ) { # Link protein to gene
			$commands[] = "{$protein_q}\tP702\t{$gene_q}" ; # Protein:encoded by:gene
		}

		$this->addSourceToCommands ( $commands ) ;
		$this->tfc->runCommandsQS ( $commands , $this->qs ) ;
	}

	# This returns an array of all Wikidata protein items for the given gene $g
	function createOrAmendProteinItems ( $g , $gene_q ) {
		$ret = [] ;
		if ( !isset($g['mRNA']) ) {
			print ( "No attributes for mRNA\n".json_encode($g)."\n" ) ;
		} else {
			foreach ( $g['mRNA'] AS $protein ) {
				if ( !isset($protein['attributes']) ) die ( "No attributes for protein\n".json_encode($g)."\n" ) ;
				$r = $this->createOrAmendProteinItem ( $gene_q , $protein ) ;
				if ( !isset($r) or $r == '' or $r === false or $r == 'Q' ) continue ; # Paranoia
				$ret[] = $r ;
			}
		}
		return $ret ;
	}

	# This returns the Wikidata item for a single protein
	function createOrAmendProteinItem ( $gene_q , $protein ) {
		$genedb_id = $protein['attributes']['ID'] ;
		$label = $genedb_id ;
		$desc = '' ;
		$literature = [] ;

		if ( isset($protein['attributes']['literature']) ) {
			foreach ( $protein['attributes']['literature'] AS $lit_id ) $literature[$lit_id] = 1 ;
		}

		$protein_i = new BlankWikidataItem ;

		# Claims
		$refs = [
			$protein_i->newSnak ( 'P248' , $protein_i->newItem('Q5531047') ) ,
			$protein_i->newSnak ( 'P813' , $protein_i->today() )
		] ;

		$protein_i->addClaim ( $protein_i->newClaim('P31',$protein_i->newItem('Q8054') , [$refs] ) ) ; # Instance of:protein
		$protein_i->addClaim ( $protein_i->newClaim('P279',$protein_i->newItem('Q8054') , [$refs] ) ) ; # Subclass of:protein
		$protein_i->addClaim ( $protein_i->newClaim('P703',$protein_i->newItem($this->gffj->species) , [$refs] ) ) ; # Found in:Species
		if ( $gene_q != 'LAST' ) $protein_i->addClaim ( $protein_i->newClaim('P702',$protein_i->newItem($gene_q) , [$refs] ) ) ; # Encoded by:gene
		$protein_i->addClaim ( $protein_i->newClaim('P3382',$protein_i->newString($genedb_id) , [$refs] ) ) ; # GeneDB ID

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
				$protein_i->addClaim ( $protein_i->newClaim($prop,$protein_i->newString($value) , [$refs] ) ) ;
			}
		}

		if ( isset($protein['attributes']) and isset($protein['attributes']['product']) and is_array($protein['attributes']['product']) ) {
			foreach ( $protein['attributes']['product'] AS $v ) {
				if ( preg_match ( '/^with=InterPro:(.+)$/' , $v , $m ) ) {
#					$protein_i->addClaim ( $protein_i->newClaim('P2926',$protein_i->newString($m[1]) , [$refs] ) ) ; # Deactivated; applies to family?
				}
			}
		}

		if ( isset($this->go_annotation[$genedb_id]) ) {
			$goann = $this->go_annotation[$genedb_id] ;
			foreach ( $goann AS $ga ) {
				$go_q = $this->getItemForGoTerm ( $ga['go'] ) ;
				if ( !isset($go_q) ) {
					print "No Wikidata item for '{$ga['go']}'!\n" ;
					continue ;
				}
				if ( !isset($this->aspects[$ga['aspect']]) ) continue ;
				$aspect_p = $this->aspects[$ga['aspect']] ;
				if ( !isset($this->evidence_codes[$ga['evidence_code']]) ) continue ;
				$evidence_code_q = $this->evidence_codes[$ga['evidence_code']] ;

				$lit_source = [] ;
				$lit_id = $ga['db_ref'] ;
				if ( $lit_id == 'WORKSHOP' ) continue ; // Huh
				$lit_q = $this->getOrCreatePaperFromID ( $lit_id ) ;
				if ( isset($lit_q) ) {
					$lit_source = [ 'P248' , $protein_i->newItem($lit_q) ] ;
				} else {
					if ( preg_match ( '/^GO_REF:(\d+)$/' , $lit_id , $m ) ) {
						$lit_source = [ 'P854' , $protein_i->newString('https://github.com/geneontology/go-site/blob/master/metadata/gorefs/goref-'.$m[1].'.md') ] ;
					} else if ( preg_match ( '/^InterPro:(.+)$/' , $ga['with_from'] , $m ) ) {
						$lit_source = [ 'P2926' , $protein_i->newString($m[1]) ] ;
					} else {
#						print "!!{$ga['with_from']} / {$lit_id}\n" ;
					}
				}

#				if ( count($lit_source) == 0 ) continue ; # Paranoia

				$qualifiers = [
					$protein_i->newSnak ( 'P459' , $protein_i->newItem($evidence_code_q) )
				] ;
				if ( isset($ga['with_from']) ) {
					if ( preg_match ( '/^Pfam:(.+)$/' , $ga['with_from'] , $m ) ) {
						$qualifiers[] = $protein_i->newSnak ( 'P3519' , $protein_i->newString($m[1]) ) ;
					}
				}

				$refs2 = [] ;
				if ( count($lit_source) > 0 ) $refs2[] = [
					$protein_i->newSnak ( $lit_source[0] , $lit_source[1] ) ,
					$protein_i->newSnak ( 'P1640' , $protein_i->newItem('Q5531047') ) ,
					$protein_i->newSnak ( 'P813' , $protein_i->today() )
				] ;
				$protein_i->addClaim ( $protein_i->newClaim($aspect_p,$protein_i->newItem($go_q) , $refs2 , $qualifiers ) ) ;
				$literature[$lit_id] = 1 ;

				if ( isset($ga['name']) and $label == $genedb_id ) {
					$label = $ga['name'] ;
					$protein_i->addAlias ( 'en' , $genedb_id ) ;
				}
				foreach ( $ga['synonym'] AS $alias ) $protein_i->addAlias ( 'en' , $alias ) ;
			}
		}

		$protein_i->addLabel ( 'en' , $label ) ;
		$protein_i->addDescription ( 'en' , $desc ) ;

		if ( isset($this->protein_genedb2q[$genedb_id]) ) {
			$protein_q = $this->protein_genedb2q[$genedb_id] ;
			$this->wil->loadItems ( [$protein_q] ) ;
			$item_to_diff = $this->wil->getItem ( $protein_q ) ;
		} else {
			$item_to_diff = new BlankWikidataItem ;
			$protein_q = 'LAST' ;
		}

		$options = [
			'ref_skip_p'=>['P813'] ,
			'labels' => [ 'ignore_except'=>['en'] ] ,
			'descriptions' => [ 'ignore_except'=>['en'] ] ,
			'aliases' => [ 'ignore_except'=>['en'] ] ,
			'remove_only' => ['P680','P681','P682']
		] ;
		$diff = $protein_i->diffToItem ( $item_to_diff , $options ) ;

		$params = (object) [
			'action' => 'wbeditentity' ,
			'data' => json_encode($diff) ,
			'summary' => 'Syncing to GeneDB (V2)' ,
			'bot' => 1
		] ;
		if ( $protein_q == 'LAST' ) $params->new = 'item' ;
		else $params->id = $protein_q ;

		if ( $params->data == '{}' ) { # No changes
			if ( $protein_q == 'LAST' ) die ( "Cannot create empty protein for gene {$gene_q}\n" ) ; # Paranoia
		} else {
			if ( !$this->qs->runBotAction ( $params ) ) {
				die ( "Failed trying to edit protein '{$label}': '{$oa->error}' / ".json_encode($qs->last_res)."\n" ) ;
			}
			if ( $protein_q == 'LAST' ) {
				$protein_q = $qs->last_res->entity->id ;
				$this->protein_genedb2q[$genedb_id] = $protein_q ;
			}
			$this->wil->updateItem ( $protein_q ) ; # Has changed
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
		$this->tfc->runCommandsQS ( $commands , $this->qs ) ;

		return $protein_q ;
	}

	function getItemForGoTerm ( $go_term , $cache = [] ) {
		if ( isset($this->go_term_cache[$go_term]) ) return $this->go_term_cache[$go_term] ;
		$sparql = "SELECT ?q { ?q wdt:P686 '{$go_term}' }" ;
#		$sparql = "SELECT DISTINCT ?q { ?q p:P686 ?wds . ?wds ?v '{$go_term}' }" ; # This works, but shouldn't be used, as is returns deprecated items
		$items = $this->tfc->getSPARQLitems ( $sparql ) ;
		if ( count($items) != 1 ) {
			return $this->tryUpdatedGoTerm ( $go_term , $cache ) ; # Fallback
		}
		$ret = $items[0] ;
		$this->go_term_cache[$go_term] = $ret ;
		return $ret ;
	}

	# In case a GO term was not found on Wikidata, try EBI if it was deprecates, they'll give the current ID instead
	function tryUpdatedGoTerm ( $go_term , $cache = [] ) {
		if ( isset($cache[$go_term]) ) return ; # Circular GO references?
		$url = "https://www.ebi.ac.uk/QuickGO/services/ontology/go/terms/{$go_term}/complete" ;
		$j = json_decode ( file_get_contents ( $url ) ) ;
		if ( !isset($j) or $j === false or $j == null or !isset($j->results) ) {
			print "No Wikidata item for GO term '{$go_term}'\n" ;
			return ;
		}
		if ( count($j->results) != 1 ) {
			print "Multiple GO terms for {$go_term} at {$url}\n";
			return ;
		}
		$cache[$go_term] = $go_term ;
		$go_term = $j->results[0]->id ;
		return $this->getItemForGoTerm ( $go_term ) ;
	}


/*
	// This works, but shouldn't be used, as is returns deprecated items
	function getItemForGoTermViaSearch ( $go_term ) {
		$url = "https://www.wikidata.org/w/api.php?action=query&list=search&srnamespace=0&format=json&srsearch=" . urlencode('haswbstatement:P686='.$go_term) ;
		$j = json_decode ( file_get_contents ( $url ) ) ;
		if ( !isset($j) or !isset($j->query)  or !isset($j->query->search) ) return ;
		$items = [] ;
		foreach ( $j->query->search AS $sr ) $items[$sr->title] = $sr->title ;
		$this->wil->loadItems ( $items ) ;
		$ret = [] ;
		foreach ( $items AS $q ) {
			$i = $this->wil->getItem ( $q ) ;
			if ( !isset($i) ) continue ;
			$values = $i->getStrings('P686') ;
			if ( !in_array($go_term,$values) ) continue ;
			$ret[] = $q ;
		}
		if ( count($ret) != 1 ) return ;
		$this->go_term_cache[$go_term] = $ret[0] ;
		return $ret[0] ;
	}
*/

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

	function run ( $genedb_id = '' ) {
		if ( !isset($genedb_id) ) $genedb_id = '' ;
		if ( $genedb_id != '' ) { # Single gene mode, usually for testing
			$this->createOrAmendGeneItem ( $this->genes[$genedb_id] ) ;
			return ;
		}
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
if ( isset($argv[2]) ) $gff2wd->init($argv[2]) ;
else $gff2wd->init() ;


function getQS () {
	global $qs ;
	return $qs ;
}

?>