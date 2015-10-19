window.tplLoadSubpageList = function()
{
	$.ajax({
		type: "POST",
		url: mw.util.wikiScript(),
		data: {
			action: 'ajax',
			rs: 'efAjaxSubpageList',
			rsargs: [ mw.config.get('wgPageName') ]
		},
		dataType: 'html',
		success: function(result)
		{
			document.getElementById('subpagelist_ajax').innerHTML = result;
		}
	});
};
