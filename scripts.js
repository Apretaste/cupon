var service = {
	// get the value from the 
	submit: function() {
		// get the coupon
		var coupon = $('#coupon').val();

		// check the coupon is not empty
		if( ! coupon) {
			M.toast({html: 'El cup&oacute;n no puede estar vac&iacute;o'});
			return false;
		}

		// send the request
		apretaste.send({
			command: "CUPONES CANJEAR", 
			data: {"coupon":coupon},
			redirect: true});
	}
}
