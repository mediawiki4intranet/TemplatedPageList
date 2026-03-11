window.tplLoadSubpageList = function() {
    new mw.Api().get({
        action: "tpl-ajax-subpages",
        page: mw.config.get("wgPageName")
    }).done(function(data) {
        document.getElementById("subpagelist_ajax").innerHTML = data["tpl-ajax-subpages"].html;
    });
};