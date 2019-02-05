#!/bin/bash
cd ~/data/gff
\rm -rf *
wget --follow-ftp -l 1 ftp://ftp.sanger.ac.uk/pub/genedb/apollo_releases/latest/*


cd ~/data/gaf
\rm -rf *
wget --follow-ftp -r -l 2 ftp://ftp.sanger.ac.uk/pub/genedb/releases/latest/*

