<?php

use Apretaste\Notifications;
use Apretaste\Money;
use Apretaste\Person;
use Apretaste\Request;
use Apretaste\Response;
use Framework\Database;
use Apretaste\Challenges;


class Service
{
	/**
	 * Main function
	 *
	 * @param \Apretaste\Request  $request
	 * @param \Apretaste\Response $response
	 *
	 * @throws \Framework\Alert
	 * @author salvipascual
	 */
	public function _main(Request $request, Response &$response)
	{
		$response->setCache('year');
		$response->setTemplate('home.ejs');
	}

	/**
	 * Apply a coupon
	 *
	 * @param \Apretaste\Request  $request
	 * @param \Apretaste\Response $response
	 *
	 * @return void
	 * @throws \Framework\Alert
	 * @author salvipascual
	 */
	public function _canjear(Request $request, Response &$response)
	{
		// get coupon from the database
		$couponCode = strtoupper($request->input->data->coupon);
		$coupon = Database::query("SELECT * FROM _cupones WHERE coupon = '$couponCode' AND active=1");

		// check if coupon cannot be found
		if (empty($coupon)) {
			$response->setTemplate('message.ejs', [
					'header' => 'El cupón no existe',
					'icon'   => 'sentiment_very_dissatisfied',
					'text'   => "El cupón insertado ($couponCode) no existe o se encuentra desactivado. Por favor revise su cupón e intente nuevamente."
			]);
			return;
		}

		// check if the coupon has been used already by the user
		$used = Database::query("SELECT COUNT(id) AS used FROM _cupones_used WHERE person_id='{$request->person->id}' AND coupon='$couponCode'")[0]->used;
		if ($used) {
			$response->setTemplate('message.ejs', [
					'header' => 'El cupón ya fue usado',
					'icon'   => 'sentiment_very_dissatisfied',
					'text'   => "Lo sentimos, pero el cupón insertado ($couponCode) ya fue usado por usted, y solo puede aplicarse una vez por usuario."
			]);
			return;
		}

		// check if the coupon reached the usage limit
		$coupon = $coupon[0];
		if ($coupon->rule_limit) {
			$cnt = Database::query("SELECT COUNT(id) AS cnt FROM _cupones_used WHERE coupon='$couponCode'")[0]->cnt;
			if ($coupon->rule_limit <= $cnt) {
				$response->setTemplate('message.ejs', [
						'header' => 'El cupón alcanzo su máximo',
						'icon'   => 'sentiment_very_dissatisfied',
						'text'   => "Este cupón ($couponCode) ha sido usado demasidas veces y ahora se encuentra desactivado."
				]);
				return;
			}
		}

		// check if the new user rule can be applied
		if ($coupon->rule_new_user) {
			$newUser = Database::query("SELECT COUNT(email) AS newuser FROM person WHERE email = '{$request->person->email}' AND DATEDIFF(NOW(), insertion_date) < 3")[0]->newuser;
			if (! $newUser) {
				$response->setTemplate('message.ejs', [
						'header' => 'El cupón no aplica',
						'icon'   => 'sentiment_very_dissatisfied',
						'text'   => "Lo sentimos, pero el cupón insertado ($couponCode) solo puede aplicarse a nuevos usuarios."
				]);
				return;
			}
		}

		// check if the deadline rule can be applied
		if ($coupon->rule_deadline) {
			if (date('Y-m-d') > date('Y-m-d', strtotime($coupon->rule_deadline))) {
				$response->setTemplate('message.ejs', [
						'header' => 'El cupón ha expirado',
						'icon'   => 'sentiment_very_dissatisfied',
						'text'   => "Lo sentimos, pero el cupón insertado ($couponCode) ha expirado y no puede ser usado."
				]);
				return;
			}
		}

		// duplicate if you are topacio level or higer
		if($request->person->level >= Level::TOPACIO) $coupon->prize_credits *= 2;

		// run powers for amulet CUPONESX2
		if(Amulets::isActive(Amulets::CUPONESX2, $request->person->id)) {
			// duplicate the amount
			$coupon->prize_credits *= 2;

			// notify the user
			$msg = "Los poderes del amuleto del Druida duplicaron el valor del cupón $couponCode";
			Notifications::alert($request->person->id, $msg, 'filter_2', '{command:"CREDITO"}}');
		}

		// add credits to the user
		try {
			Money::send(Money::BANK, $request->person->id, $coupon->prize_credits, "Canjeo del cupón $couponCode");
		} catch (Exception $e) {
			$response->setTemplate('message.ejs', [
					'header' => 'Error inesperado',
					'icon'   => 'sentiment_very_dissatisfied',
					'text'   => 'Hemos encontrado un error. Por favor intente nuevamente, si el problema persiste, escríbanos al soporte.'
			]);
			return;
		}

		// create coupon record in the database
		Database::query("INSERT INTO _cupones_used (coupon, person_id) VALUES ('$couponCode', {$request->person->id})");

		// add the experience
		Level::setExperience('COUPON_EXCHANGE', $request->person->id);

		// complete the challenge
		Challenges::complete('cupon', $request->person->id);

		// offer rewards response
		$response->setTemplate('message.ejs', [
				'header' => '¡Felicidades!',
				'icon'   => 'sentiment_very_satisfied',
				'text'   => "Su cupón se ha canjeado correctamente y usted ha ganado §{$coupon->prize_credits} en créditos de Apretaste. Gracias por canjear su cupón."
		]);
	}
}