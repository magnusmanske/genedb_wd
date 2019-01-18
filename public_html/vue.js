'use strict';

let router ;
let app ;
let wd = new WikiData() ;


$(document).ready ( function () {
	Promise.all ( [
		vue_components.loadComponents ( ['wd-link','wd-date','tool-translate','tool-navbar','publication','commons-thumbnail',
			'chromosome_plot.html',
			'gene_search_box.html',
			'genedb_header.html',
			'genedb_footer.html',
			'main_page.html',
			'search_page.html',
			'gene_list.html',
			'gene.html',
			'species.html',
			'go_term.html',
			'chromosome.html',
			'protein_box.html'
		] ) ,
//		new Promise(function(resolve, reject) { resolve() } )
	] )	.then ( () => {
			wd_link_wd = wd ;
			const routes = [
			  { path: '/', component: MainPage , props:true },
			  { path: '/gene/:genedb_id', component: Gene , props:true },
			  { path: '/go/:go_term', component: GoTerm , props:true },
			  { path: '/search', component: SearchPage , props:true },
			  { path: '/search/:query', component: SearchPage , props:true },
			  { path: '/species/:species_id', component: SpeciesPage , props:true },
			  { path: '/chromosome/:q_chromosome', component: Chromosome , props:true },
			] ;
			router = new VueRouter({routes}) ;
			app = new Vue ( { router } ) .$mount('#app') ;
		} ) ;
} ) ;
