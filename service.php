<?php

class Cupones extends Service
{
	/**
	 * Main function
	 *
	 * @param Request
	 * @return Response
	 */
	public function _main(Request $request)
	{
		$response = new Response();
		$response->setCache("year");
		$response->setResponseSubject("Canjear cupon");
		$response->createFromTemplate("basic.tpl", []);
		return $response;
	}

	/**
	 * Apply a coupon
	 *
	 * @param Request
	 * @return Response
	 */
	public function _canjear(Request $request)
	{
		// get coupon from the database
		$couponCode = strtoupper($request->query);
		$coupon = Connection::query("SELECT * FROM _cupones WHERE coupon = '$couponCode' AND active=1");

		// check if coupon cannot be found
		if(empty($coupon)) {
			$text = "El cupon insertado ($couponCode) no existe o se encuentra desactivado. Por favor revise los caracteres insertados e intente nuevamente.";
			goto display;
		} $coupon = $coupon[0];

		// check if the coupon has been used already by the user
		$used = Connection::query("SELECT COUNT(id) AS used FROM _cupones_used WHERE email='{$request->email}' AND coupon='$couponCode'")[0]->used;
		if($used) {
			$text = "Lo sentimos, pero el cupon insertado ($couponCode) ya fue usado por usted, y solo puede aplicarse una vez por usuario.";
			goto display;
		}

		// check if the coupon reached the usage limit
		if($coupon->rule_limit) {
			$cnt = Connection::query("SELECT COUNT(id) AS cnt FROM _cupones_used WHERE coupon='$couponCode'")[0]->cnt;
			if($coupon->rule_limit <= $cnt) {
				$text = "Este cupon ($couponCode) ha sido usado demasidas veces y ahora se encuentra desactivado.";
				goto display;
			}
		}

		// check if the new user rule can be applied
		if($coupon->rule_new_user) {
			$newUser = Connection::query("SELECT COUNT(email) AS newuser FROM person WHERE email = '{$request->email}' AND DATEDIFF(NOW(), insertion_date) < 3")[0]->newuser;
			if( ! $newUser) {
				$text = "Lo sentimos, pero el cupon insertado ($couponCode) solo puede aplicarse a nuevos usuarios.";
				goto display;
			}
		}

		// check if the deadline rule can be applied
		if($coupon->rule_deadline) {
			if(date('Y-m-d') > date('Y-m-d', strtotime($coupon->rule_deadline))) {
				$text = "Lo sentimos, pero el cupon insertado ($couponCode) ha expirado y no puede ser usado.";
				goto display;
			}
		}

		// offer rewards message
		$credits = $coupon->prize_credits;
		Connection::query("UPDATE person SET credit=credit+$credits WHERE email='{$request->email}'");
		$text = "&iexcl;Felicidades! Su cupon se ha canjeado correctamente y usted ha ganado <b>&sect;$credits en creditos de Apretaste</b>. Gracias por canjear su cupon.";

		// create records of your interaction
		Connection::query("INSERT INTO _cupones_used(coupon, email) VALUES ('$couponCode', '{$request->email}')");

		// return response
		display:
		$response = new Response();
		$response->createFromTemplate("message.tpl", ["text"=>$text]);
		return $response;
	}
}
