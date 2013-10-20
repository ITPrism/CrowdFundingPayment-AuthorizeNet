jQuery(document).ready(function() {
	 
	jQuery("#js-cfpayment-toggle-fields").on("click", function(event){
		event.preventDefault();
		jQuery('#js-cfpayment-authorizenet').toggle("slow");
	});
	
	
});