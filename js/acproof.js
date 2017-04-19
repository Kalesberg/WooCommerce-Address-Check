jQuery(document).ready(function($) {
	$('.close').click(function() {
		var cls = $(this).attr('data-dismiss');
		$(this).closest('.' + cls).hide();
	});
});