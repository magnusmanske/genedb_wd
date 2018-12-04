<?PHP

# Based on https://github.com/lskatz/biophp.git
class GFF{
  var $GFF; #array of lines from the GFF file
  var $version; # version number of the GFF (3 is default)
  var $lineNumber=0;
  var $numLines;
  function GFF($filename,$version=3){
    $line = file_get_contents ( $filename ) ;
    if ( preg_match('/\.gz$/',$filename) ) $line = gzdecode ( $line ) ;
    $line = explode ( "\n" , $line ) ;
    $line=array_map("trim",$line);
    $this->GFF=$line;
    $this->numLines=count($this->GFF);
    $this->version=$version;
  }
  function parseGffLine($line){
    if ( substr($line,0,1) == '#' ) return ['comment'=>$line] ;
    if ( substr($line,0,1) == '>' ) return false ; // Don't want the raw sequence

    $cell=split("\t",$line);
    
    # create an associative array for the GFF
    foreach ( ['seqid','source','type','start','end','score','strand','phase'] AS $k => $v ) {
      if ( isset($cell[$k]) ) $info[$v] = $cell[$k] ;
      else $info[$v] = '' ;
    }

    if ( isset($cell[8]) ) {
      $attributes=$cell[8];

      # parse the attributes
      $attributes=split(';',$attributes);
      # each attribute should be in key=value format
      $numAttributes=count($attributes);
      for($i=0;$i<$numAttributes;$i++){
        $attribute=$attributes[$i];
        list($key,$value)=split("=",$attribute);
        $key=urldecode($key);
        $value=urldecode($value);

        # Format doc: https://github.com/The-Sequence-Ontology/Specifications/blob/master/gff3.md

        if ( in_array ( $key , ['previous_systematic_id','literature','Dbxref','Ontology_term','orthologous_to'] ) ) $value = explode ( ',' , $value ) ;
        if ( in_array ( $key , ['gPI_anchor_cleavage_site','product'] ) ) $value = explode ( ';' , $value ) ;
/*        if ( $key == 'polypeptide_domain' ) {
          print "\n$value\n\n" ;
          $i = preg_match_all ( '/([^;]*);([^;]*);([^;]*);([^;]*);([^;]*);([^;]*);([^;,]*),{0,1}/' , $value , $m , PREG_SET_ORDER ) ;
          print "$i: " ; print_r ( $m ) ;
        }*/

        $attributeArr[$key]=$value;
      }
      $info['attributes']=$attributeArr;
    } else $info['attributes'] = [] ;

    return $info;
  }
  /**
   * retrieves a gff entry, given the row number
   */ 
  function entry($i){
    return $this->parseGffLine($this->GFF[$i]);
  }
  function nextEntry(){
    $i=$this->lineNumber;
    if($i>=$this->numLines) return false;
    $this->lineNumber++;
    return $this->entry($i);
  }
}

# See http://geneontology.org/page/go-annotation-file-gaf-format-21
class GAF{
  var $GAF; #array of lines from the GAF file
  var $version; # version number of the GAF (1 is default)
  var $lineNumber=0;
  var $numLines;
  function GAF($filename,$version=1){
    $line = file_get_contents ( $filename ) ;
    if ( preg_match('/\.gz$/',$filename) ) $line = gzdecode ( $line ) ;
    $line = explode ( "\n" , $line ) ;
    $line=array_map("trim",$line);
    $this->GAF=$line;
    $this->numLines=count($this->GAF);
    $this->version=$version;
  }
  function parseGAFLine($line){
    if ( substr($line,0,1) == '!' ) return ['header'=>$line] ; // Header

    $cell=split("\t",$line);
    
    # create an associative array for the GAF
    foreach ( ['db','id','symbol','qualifier','go','db_ref','evidence_code','with_from','aspect','name','synonym','type','taxon','date','assigned_by','extension','product'] AS $k => $v ) {
      if ( isset($cell[$k]) ) $info[$v] = $cell[$k] ;
      else $info[$v] = '' ;
    }
    foreach ( ['synonym']  AS $k ) $info[$k] = explode ( '|' , $info[$k] ) ;

    return $info;
  }
  /**
   * retrieves a GAF entry, given the row number
   */ 
  function entry($i){
    return $this->parseGAFLine($this->GAF[$i]);
  }
  function nextEntry(){
    $i=$this->lineNumber;
    if($i>=$this->numLines) return false;
    $this->lineNumber++;
    return $this->entry($i);
  }
}


?>