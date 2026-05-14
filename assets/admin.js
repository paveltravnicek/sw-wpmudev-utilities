document.addEventListener('DOMContentLoaded', function () {

	// ── Přesun WP notices pod hero ──────────────────────────────
	var noticesArea = document.querySelector('.swu-page-notices');
	if (noticesArea) {
		var wrap = document.querySelector('.swu-wrap');
		if (wrap) {
			var searchRoot = document.getElementById('wpbody-content') || document.body;
			var toMove = [];
			searchRoot.querySelectorAll('.notice, div.updated, div.error').forEach(function (el) {
				if (!wrap.contains(el)) toMove.push(el);
			});
			toMove.forEach(function (el) {
				noticesArea.insertBefore(el, noticesArea.firstChild);
			});
		}
	}

});
