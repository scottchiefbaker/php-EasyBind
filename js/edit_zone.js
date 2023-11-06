$(document).ready(function() {
	$("._edit_record").on("click", function(event) {
		event.preventDefault();

		var rec_num  = $(this).data('rec_num');
		var rec_type = $(this).data('rec_type');
		var key      = $(this).data('rec_key');
		var val      = $(this).data('rec_val');

		key = atob(key);
		val = atob(val);

		console.log("%s %s %s %s", rec_num, rec_type, key, val);

		$("._form_key").val(key);
		$("._form_value").val(val);
		$("._form_type").val(rec_type);
		$("._form_button").html("Update");
		$("._form_action").val("update_record");
		$("._form_record_num").val(rec_num);
	});
});
