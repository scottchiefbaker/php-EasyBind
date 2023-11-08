$(document).ready(function() {
	init_edit();
});

function init_edit() {
	console.log("Init edit");

	$("._edit_record").on("click", function(event) {
		event.preventDefault();

		var elem = $(this).parent().parent();
		// Find the update form, put it after this element, and show it
		$(".update_form").insertAfter(elem).removeClass('d-none');

		var rec_num  = $(this).data('rec_num');
		var rec_type = $(this).data('rec_type');
		var key      = $(this).data('rec_key');
		var val      = $(this).data('rec_val');

		key = atob(key);
		val = atob(val);

		console.log("%s %s %s %s", rec_num, rec_type, key, val);

		$("#up_key").val(key);
		$("#up_value").val(val);
		$("#up_rec_num").val(rec_num);
		$("#up_type").val(rec_type);

		if (key) {
			$("#up_key").focus();
		} else if (val) {
			$("#up_value").focus();
		}
	});
}
