$(document).ready(function() {
	init_filter();
});

function init_filter() {
	$("._filter").on("keyup", function() {
		$(".zone_item").addClass('d-none');

		var filter_val = $("._filter").val();

		$(".zone_item").each(function() {
			var str = $("a", this).html();

			if (str.includes(filter_val)) {
				$(this).removeClass('d-none');
			}
		});
	});
}
