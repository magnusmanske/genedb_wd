<?php

require_once ( '/data/project/genedb/public_html/php/wikidata.php' ) ;
require_once ( '/data/project/genedb/public_html/php/ToolforgeCommon.php' ) ;
require_once ( '/data/project/sourcemd/scripts/orcid_shared.php' ) ;


class EMBL {
	public $data = [] ;

	private function unquote ( $s ) {
		return preg_replace ( '/^"(.+)"/' , '$1' , $s ) ;
	}

	private function flushElement ( $e ) {
		if ( !isset($e['locus_tag']) ) return ;
		$id = $this->unquote ( $e['locus_tag'][0] ) ;
		if ( count($e['locus_tag']) != 1 ) die ( "Bad locus tag number for {$id}\n" ) ;
		$e['__id'] = $id ;
		foreach ( $e AS $key => $array ) { // Remove quotes around values
			if ( preg_match ( '/^__/' , $key ) ) continue ;
			if ( !is_array($array) ) continue ;
			foreach ( $array AS $k => $v ) $e[$key][$k] = $this->unquote ( $v ) ;
		}
		$this->data[$id] = $e ;
	}

	public function readFromFile ( $filename ) {
		if ( !file_exists($filename) ) die ( "EMBL:readFromFile : No such file {$filename}\n" ) ;
		$is_gz = preg_match ( '/\.(gz|gzip)$/' , $filename ) ;
		$fh = $is_gz ? gzopen($filename,'r') : fopen($filename,'r') ;

		$last_tag = '' ;
		$tmp = [] ;
		while ( ($row=($is_gz?gzgets($fh):fgets($fh))) !== false ) { // Read every row
			$row = trim ( $row ) ;
			if ( preg_match ( '/^SQ\s/' , $row ) ) break ; // Begin of sequence, we're done here
			if ( !preg_match ( '/^FT\s{3}(\S*\s+)(.+)$/' , $row , $m ) ) continue ;
			$type = strtolower ( trim ( $m[1] ) ) ;
			$t = trim ( $m[2] ) ;
			if ( $type != '' ) {
				$this->flushElement ( $tmp ) ;
				$tmp = [ '__type' => $type , '__region' => $t ] ;
				$last_tag = '' ;
				continue ;
			}
			if ( preg_match ( '/\/(.+?)=(.*)$/' , $t , $m ) ) {
				$last_tag = strtolower(trim($m[1])) ;
				$tmp[$last_tag][] = $m[2] ;
			} else if ( $last_tag != '' ) {
				$tmp[$last_tag][count($tmp[$last_tag])-1] .= $t ;
			}
		}
		$this->flushElement ( $tmp ) ;

		if ( $is_gz ) gzclose($fh) ;
		else fclose($fh) ;
	}
} ;

class Gene {
	public $id = '' , $label = '' , $description = '' , $chromosome = '' , $start = 0 , $end = 0 , $taxon = '' ;
	public $aliases = [] ;
	public $transcripts = [] ;
	public $meta = [] ;
	public $q ;
	public $i ;
	public $parent ;

	public function syncToWikidata () {
		if ( !isset($this->parent) ) die ( "No patent for Gene {$this->id}\n" ) ;
		if ( !isset($this->i) ) return $this->parent->log ( ['no_wikidata_item',$this->id] ) ;

		$commands = [] ;

		// Label
		if ( $this->label != '' and $this->label != $this->i->getLabel('en') ) {
			$commands[] = [ $this->q , 'Len' , '"' . $this->label . '"' ] ;
		}

		// Description
		if ( $this->description != '' and $this->description != $this->i->getDesc('en') ) {
			$commands[] = [ $this->q , 'Den' , '"' . $this->description . '"' ] ;
		}

		// Aliases
		$aliases = $this->i->getAliases ( 'en' ) ;
		if ( $this->label != $this->id ) $aliases[] = $this->id ;
		foreach ( $this->aliases AS $a ) {
			if ( !in_array ( $a , $aliases ) ) $commands[] = [ $this->q , 'Aen' , '"'.$a.'"' ] ;
		}
		foreach ( $aliases AS $a ) {
			if ( $a != $this->id and !in_array ( $a , $this->aliases ) ) $this->parent->log ( [ 'alias_in_wikidata_but_not_in_genedb' , $this->q , $a ] ) ;
		}

		// Subclass/Instance
		if ( !$this->i->hasTarget ( 'P31' , 'Q7187' ) ) $commands[] = [ $this->q , 'P31' , 'Q7187' ] ;
		if ( $this->i->hasTarget ( 'P279' , 'Q7187' ) ) $commands[] = [ '-'.$this->q , 'P279' , 'Q7187' ] ;

		// GeneDB IDs (get rid of transcripts, ensure main ID is there)
		$genedb_ids = $this->i->getStrings ( 'P3382' ) ;
		if ( !in_array ( $this->id , $genedb_ids) ) $commands[] = [ $this->q , 'P3382' , '"'.$this->id.'"' ] ;
		foreach ( $genedb_ids AS $id ) {
			if ( preg_match ( '/\./' , $id ) ) $commands[] = [ '-'.$this->q , 'P3382' , '"'.$id.'"' ] ;
		}

		// Check chromosome
		$chromosome_q = $this->parent->taxon_data->chromosomes[$this->chromosome] ;
		if ( isset($chromosome_q) ) {
			if ( !$this->i->hasTarget ( 'P1057' , $chromosome_q ) ) $commands[] = [ $this->q , 'P1057' , $chromosome_q ] ;
		} else $this->parent->log ( [ 'chromosome_not_in_wikidata' , $this->q , $this->chromosome ] ) ;

		// Check start
		if ( $this->start != 0 and !$this->itemHasStringStatementWithQualifier('P644',$this->start,'P659',$this->parent->taxon_data->reference_q) ) {
			$commands[] = [ '-'.$this->q , 'P644' , '"'.$this->start.'"' ] ; # TODO potentially dangerous, remove once initial sync is done
			$commands[] = [ $this->q , 'P644' , '"'.$this->start.'"' , 'P659' , $this->parent->taxon_data->reference_q , 'P1057' , $chromosome_q ] ;
		}

		// Check end
		if ( $this->end != 0 and !$this->itemHasStringStatementWithQualifier('P645',$this->end,'P659',$this->parent->taxon_data->reference_q) ) {
			$commands[] = [ '-'.$this->q , 'P645' , '"'.$this->end.'"' ] ; # TODO potentially dangerous, remove once initial sync is done
			$commands[] = [ $this->q , 'P645' , '"'.$this->end.'"' , 'P659' , $this->parent->taxon_data->reference_q , 'P1057' , $chromosome_q ] ;
		}

		// Check taxon (ignoring own taxon var...)
		if ( !$this->i->hasTarget ( 'P703' , $this->parent->taxon_data->taxon_q) ) $commands[] = [ $this->q , 'P703' , $this->parent->taxon_data->taxon_q ] ;

		// Check external IDs
		if ( isset($this->meta['external_ids']) ) {
			foreach ( $this->meta['external_ids'] AS $prop => $values ) {
				foreach ( $values AS $v ) {
					if ( in_array ( $v , $this->i->getStrings($prop) ) ) continue ;
					$commands[] = [ $this->q , $prop , '"'.$v.'"' ] ;
				}
			}
		}

		// Check GO
		if ( isset($this->meta['go']) ) {
			foreach ( $this->meta['go'] AS $go => $go_data ) {
				foreach ( $go_data['references'] AS $reference ) {
					$this->addGoWithReference ( $go , $reference , $commands ) ;
				}
			}
			// TODO log "not found" references, via claim->reference_is_in_genedb
		}

		if ( count($commands) == 0 ) return ; // Nothing to do

		$this->finalizeCommands ( $commands ) ;
//print_r ( $commands ) ;

		$qs = getQS() ;
		$qs->use_command_compression = true ;
		$tmp = $qs->importData ( implode("\n",$commands) , 'v1' ) ;
		$tmp['data']['commands'] = $qs->compressCommands ( $tmp['data']['commands'] ) ;
		$qs->runCommandArray ( $tmp['data']['commands'] ) ;
//exit(0);
		return $qs->last_item ;
	}

	private function addGoWithReference ( $go , $ref , &$commands ) {
		$go_q = $this->parent->getGoQ ( $go ) ;
		if ( !isset($go_q) ) return ; // TODO log error

		if ( preg_match ( '/^PMID:(.+)$/',$ref->reference,$m) ) $ref_q = $this->parent->getReferenceQ ( 'P698' , $m[1] ) ;
		else return $this->parent->log ( [ 'unknown_reference_type' , $this->q , $ref->reference ] ) ;
		if ( !isset($ref_q) ) return $this->parent->log ( [ 'no_paper_item' , $ref->reference ] ) ;

		$evidence_q = $this->parent->evidence_codes[$ref->evicence_code] ;
		if ( !isset($evidence_q) ) return $this->parent->log ( [ 'no_item_for_evidence_code' , $ref->evicence_code ] ) ;

		$aspect_p = $this->parent->aspect_codes[$ref->aspect] ;
		if ( !isset($aspect_p) ) return $this->parent->log ( [ 'no_property_for_aspect' , $ref->aspect ] ) ;

		// TODO with/from
		$claims = $this->i->getClaims ( $aspect_p ) ;
		foreach ( $claims AS $c ) {
			if ( $c->mainsnak->datavalue->value->id != $go_q ) continue ;
			if ( !isset($c->references) ) continue ;
			foreach ( $c->references AS $reflist ) {
				if ( !isset($reflist->snaks->P248) ) continue ; // "stated in"
				if ( !isset($reflist->snaks->P459) ) continue ; // "determination method"
				$found = 0 ;
				foreach ( $reflist->snaks->P248 AS $snak ) {
					if ( $snak->datavalue->value->id != $ref_q ) continue ;
					$found++ ;
					break ;
				}
				foreach ( $reflist->snaks->P459 AS $snak ) {
					if ( $snak->datavalue->value->id != $evidence_q ) continue ;
					$found++ ;
					break ;
				}
				if ( $found == 2 ) {
					$c->reference_is_in_genedb = true ;
					return ;
				}
			}
		}

		$retrieved_date = date ( 'Y-m-d' ) ;
		$retrieved_date = '+'.$retrieved_date.'T00:00:00Z/11' ;
		$commands[] = [ '-'.$this->q , $aspect_p , $go_q ] ; // TODO remove this line after one-time sync
		$commands[] = [ $this->q , $aspect_p , $go_q , 'S248' , $ref_q , 'S459' , $evidence_q , 'S813' , $retrieved_date ] ;
	}

	private function itemHasStringStatementWithQualifier ( $p1 , $s1 , $p2 , $q2 ) {
		$claims = $this->i->getClaims ( $p1 ) ;
		foreach ( $claims AS $c ) {
			if ( $c->mainsnak->datavalue->value != $s1 ) continue ;
			if ( !isset($c->qualifiers) ) continue ;
			if ( !isset($c->qualifiers->$p2) ) continue ;
			foreach ( $c->qualifiers->$p2 AS $c2 ) {
				if ( $c2->datavalue->value->id != $q2 ) continue ;
				return true ;
			}
		}
		return false ;
	}

	private function finalizeCommands ( &$commands ) {
		foreach ( $commands AS $c ) {
			if ( substr($c[0],0,1) == '-' ) continue ;
			if ( substr($c[1],0,1) != 'P' ) continue ;
			// TODO add "source:GeneDB", and maybe date, to commands
		}
		foreach ( $commands AS $k => $c ) {
			$commands[$k] = implode ( "\t" , $c ) . " /* GeneDB sync #genedb */" ;
		}
	}

} ;

class Genes {
	public $genedb_name = 'GeneDB_Pfalciparum' ; # HARDCODED TODO FIXME
	public $genes = [] ;
	public $taxon_data ;
	public $logfile_name ;
	public $aspect_codes = [ 'P' => 'P682' , 'F' => 'P680' , 'C' => 'P681' ] ;
	public $evidence_codes = [] ;

	private $external_id_codes = [ 'uniprotkb' => 'P352' ] ;
	private $logfile_handle ;
	private $sparql_result_cache = [] ;
	private $wil ;


	function __construct() {
		$this->wil = new WikidataItemList ;
	}

	function __destruct() {
		if ( isset($this->logfile_handle) ) {
			fwrite ( $this->logfile_handle , "\n]\n" ) ;
			fclose ( $this->logfile_handle ) ;
		}
	}

	public function log ( $a ) {
		if ( isset($this->logfile_name) ) {
			if ( !isset($this->logfile_handle) ) {
				$this->logfile_handle = fopen ( $this->logfile_name , 'w' ) ;
				fwrite ( $this->logfile_handle , "[\n" . json_encode ( $a ) ) ;
			} else {
				fwrite ( $this->logfile_handle , ",\n" . json_encode ( $a ) ) ;
			}
		} else {
			print json_encode ( $a ) . "\n" ;
		}
	}

	private function getAndCacheItemForSPARQL ( $sparql , $what ) {
		$items = [] ;
		if ( isset($this->sparql_result_cache[$sparql]) ) {
			$items = $this->sparql_result_cache[$sparql] ;
		} else {
			$tfc = new ToolforgeCommon ;
			$items = $tfc->getSPARQLitems ( $sparql ) ;
			$this->sparql_result_cache[$sparql] = $items ;
		}
		if ( count($items) == 1 ) {
			$q = $items[0] ;
			return $q ;
		}
		if ( count($items) == 0 ) $this->log ( [ 'no_item_for_'.$what , $go ] ) ;
		else $this->log ( [ 'multiple_item_for_'.$what , $go ] ) ;
	}

	public function getReferenceQ ( $prop , $v ) {
		$ref_q = $this->getAndCacheItemForSPARQL ( "SELECT ?q { ?q wdt:{$prop} '{$v}' }" , $prop ) ;
		if ( isset($ref_q) ) return $ref_q ;
		print "TRYING TO CREATE NEW PUBLICATION FOR {$prop}:'{$v}' => " ;
		if ( $prop == 'P698' ) $ref_q = getOrCreateWorkFromIDs ( ['pmid'=>$v] ) ;
		print "https://www.wikidata.org/wiki/{$ref_q}\n" ;
		return $ref_q ;
	}

	public function getGoQ ( $go ) {
		return $this->getAndCacheItemForSPARQL ( "SELECT ?q { ?q wdt:P686 '{$go}' }" , 'go_term' ) ;
	}



	public function findWikidataItems () {
		$sparql = "SELECT ?q ?genedb { VALUES ?prop { wdt:P279 wdt:P31 } ?q wdt:P3382 ?genedb ; wdt:P703 wd:{$this->taxon_data->taxon_q} ; ?prop wd:Q7187 }" ; # TODO P279=>P31
		$tfc = new ToolforgeCommon ;
		$j = $tfc->getSPARQL ( $sparql ) ;
		foreach ( $j->results->bindings AS $b ) {
			$id = preg_replace ( '/\..*$/' , '' , $b->genedb->value ) ;
			$q = $tfc->parseItemFromURL ( $b->q->value ) ;
			if ( isset($this->genes[$id] ) ) $this->genes[$id]->q = $q ;
			else $this->log ( [ 'gene_on_wd_not_in_genedb' , $id , $q ] ) ;
		}
	}

	public function getWikidataItems () {
		$to_load = [] ;
		foreach ( $this->genes AS $g ) {
			if ( isset($g->q) ) $to_load[] = $g->q ;
		}
		$this->wil->loadItems($to_load) ;
		foreach ( $this->genes AS $g ) {
			if ( isset($g->q) ) $g->i = $this->wil->getItem ( $g->q ) ;
		}

	}

	public function loadGenesFromPHP ( $filename ) {
		$this->genes = unserialize ( file_get_contents ( $filename ) ) ;
		foreach ( $this->genes AS $g ) $g->parent = $this ;
	}

	public function loadTaxonData ( $q ) {
		$this->taxon_data = (object) [ 'taxon_q' => $q , 'chromosomes' => [] ] ;
		$sparql = 'SELECT ?q ?qLabel { ?q wdt:P31 wd:Q37748 ; wdt:P703 wd:' . $q . ' . SERVICE wikibase:label { bd:serviceParam wikibase:language "en" } }' ;
		$tfc = new ToolforgeCommon ;
		$j = $tfc->getSPARQL ( $sparql ) ;
		foreach ( $j->results->bindings AS $b ) {
			$label = $b->qLabel->value ;
			$cq = $tfc->parseItemFromURL ( $b->q->value ) ;
			$this->taxon_data->chromosomes[$label] = $cq ;
		}
	}

	public function loadEvidenceCodes () {
		$tfc = new ToolforgeCommon ;
		$sparql = 'SELECT ?q ?qLabel { ?q wdt:P31 wd:Q23173209 SERVICE wikibase:label { bd:serviceParam wikibase:language "en" } }' ;
		$j = $tfc->getSPARQL ( $sparql ) ;
		foreach ( $j->results->bindings AS $b ) {
			$label = $b->qLabel->value ;
			$eq = $tfc->parseItemFromURL ( $b->q->value ) ;
			$this->evidence_codes[$label] = $eq ;
		}
	}

	public function importFromEmbl ( $filename , $chromosome ) {
		if ( !isset($this->taxon_data->chromosomes[$chromosome]) ) die ( "Genes::importFromEmbl : No such chromosome '{$chromosome}'\n" ) ;
		$embl = new EMBL ;
		$embl->readFromFile ( $filename ) ;
		foreach ( $embl->data AS $id => $e ) {
			$this->createOrMergeFromEMBL ( $e , $chromosome ) ;
		}
	}

	public function importFromGAF ( $filename ) {
		if ( !file_exists($filename) ) die ( "Genes::importFromGAF : File {$filename} does not exist\n" ) ;
		$fh = fopen ( $filename , 'r' ) ;
		$header = trim ( fgets ( $fh ) ) ;
		if ( !preg_match ( '/^!gaf-version: (\d+)\.(\d+)$/' , $header , $m ) ) die ( "Genes::importFromGAF : File {$filename} is not GAF\n" ) ;
		list ( , $major , $minor ) = $m ;
		if ( $major == 1 ) {
			$this->importFromGAF_V1 ( $fh ) ;
		} else {
			die ( "Genes::importFromGAF : File {$filename} has unsupported GAF version: {$header}\n" ) ;
		}
		fclose ( $fh ) ;
	}

	private function createOrMergeFromEMBL ( $e , $chromosome ) {
		global $external_id_codes ;
		if ( !isset($e['__type']) ) return ;
		if ( $e['__type'] != 'cds' ) return ; // CDS only
		$id = preg_replace ( '/\..*$/' , '' , $e['__id'] ) ; // Main gene ID only
		if ( !isset($id) ) return ;

		if ( !isset($this->genes[$id]) ) {
			$g = new Gene ( $this ) ;
			$g->parent = $this ;
			$g->id = $id ;
			$this->genes[$id] = $g ;
		} else $g = $this->genes[$id] ;
		// $g is now shotcut for $this->genes[$id]

		if ( $g->chromosome == '' ) $g->chromosome = $chromosome ;

		// Extract genomic position
		if ( ( $g->start == 0 or $g->end == 0 ) and isset($e['__region']) ) {
			if ( preg_match_all ( '/(\d+)/' , $e['__region'] , $m ) ) {
				$g->start = 0 ;
				$g->end = 0 ;
				foreach ( $m[1] AS $pos ) {
					$pos *= 1 ;
					if ( $g->start == 0 or $pos < $g->start ) $g->start = $pos ;
					if ( $g->end < $pos ) $g->end = $pos ;
				}
			}
		}

		// primary_name => label DONE
		// synonym => aliases DONE
		// previous_systematic_id => aliases DONE
		// db_xref DONE
		// go DONE

		// colour | IGNORE wtf is that?
		// controlled_curation | IGNORE wtf is that?
		// curation | IGNORE wtf is that?
		// note | IGNORE; No good place in WD
		// literature | IGNORE; done via GO (references)
		// locus_tag | IGNORE == ID
		// ec_number ???
		// other_transcript ???
		// shared_id ???

		// product | TODO maybe create protein item?


		if ( isset($e['primary_name']) and $g->label == '' ) {
			$g->label = $e['primary_name'][0] ;
		}
		if ( isset($e['synonym']) ) {
			foreach ( $e['synonym'] AS $v ) $g->aliases[$v] = $v ;
		}
		if ( isset($e['previous_systematic_id']) ) {
			foreach ( $e['previous_systematic_id'] AS $v ) $g->aliases[$v] = $v ;
		}
		if ( isset($e['db_xref']) ) {
			foreach ( $e['db_xref'] AS $v ) {
				if ( !preg_match ( '/^(.+):(.+)$/' , $v , $m ) ) continue ;
				$db = strtolower($m[1]) ;
				if ( !isset($external_id_codes[$db]) ) continue ; // Unknown database TODO show those
				$prop = $external_id_codes[$db] ;
				$g->meta['external_ids'][$prop][$m[2]] = $m[2] ;
			}
		}
		if ( isset($e['go']) ) {
			foreach ( $e['go'] AS $ref_text ) {
				$kv = [] ;
				foreach ( explode ( ';' , $ref_text ) AS $part ) {
					$tmp = explode ( '=' , $part , 2 ) ;
					$kv[strtolower($tmp[0])] = $tmp[1] ;
				}
				if ( !isset($kv['goid']) ) continue ;
				if ( !isset($kv['db_xref']) ) continue ;
				if ( !isset($kv['evidence']) ) continue ;

				if ( isset($g->meta['go'][$kv['goid']]['references'][$kv['db_xref']]) ) continue ;
				$tmp = (object) [
					'reference' => $kv['db_xref'] ,
					'evicence_code' => $kv['evidence']
				] ;
				if ( isset($kv['aspect']) ) $tmp->aspect = $kv['aspect'] ;
				$g->meta['go'][$kv['goid']]['references'][$kv['db_xref']] = $tmp ;
			}
		}
	}

	private function importFromGAF_V1 ( $fh ) {
		while ( ($row=fgets($fh)) !== false ) { // Read every row
			$row = trim ( $row ) ;
			if ( $row == '' ) continue ; // Ignore blank lines
			$parts = explode ( "\t" , $row ) ; // 0-based numbering, so one less than in http://geneontology.org/page/go-annotation-file-gaf-format-10
			if ( count($parts) != 15 ) die ( "Genes::importFromGAF_V1 : Expected 15 columns, got " . count($parts) . "\n" ) ;
			if ( $parts[0] != $this->genedb_name ) continue ; // Paranoia
			if ( $parts[11] != 'transcript' ) continue ; // Paranoia
			$id = preg_replace ( '/\..*$/' , '' , $parts[1] ) ; // Remove transcript sub-ID
			if ( !isset($this->genes[$id]) ) {
				$g = new Gene ;
				$g->parent = $this ;
				$g->id = $id ;
				$this->genes[$id] = $g ;
			} else $g = $this->genes[$id] ;
			// $g is now shotcut for $this->genes[$id]

			if ( $g->label == '' ) $g->label = $parts[2] ;
			if ( $g->label == '' ) $g->label = $id ;
			if ( $g->description == '' ) $g->description = $parts[9] ;
			if ( $g->taxon == '' ) $g->taxon = $parts[12] ;

			$g->transcripts[$parts[1]] = $parts[1] ;

			foreach ( explode('|',$parts[10]) AS $alias ) $g->aliases[$alias] = $alias ;

			if ( $parts[3] == '' ) { // Qualifier empty
				if ( $parts[4] != '' ) {
					foreach ( explode('|',$parts[5]) AS $ref ) {
						$g->meta['go'][$parts[4]]['references'][$ref] = (object) [
							'reference' => $ref , 
							'evicence_code' => $parts[6] , 
							'with_from' => $parts[7] , 
							'aspect' => $parts[8]
						] ;
					}
				}
			}
		}
	}

} ;

?>