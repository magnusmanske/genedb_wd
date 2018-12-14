#!/bin/bash
cd ~/data/gff
\rm -rf *
wget --follow-ftp -l 1 ftp://ftp.sanger.ac.uk/pub/genedb/apollo_releases/latest/*


cd ~/data/gaf
\rm -rf *
wget --follow-ftp -l 1 ftp://ftp.sanger.ac.uk/pub/project/pathogens/gff3/CURRENT/*.gaf

