<?php

use Apretaste\Alert;
use Apretaste\Money;
use Apretaste\Level;
use Apretaste\Request;
use Apretaste\Amulets;
use Apretaste\Response;
use Apretaste\Challenges;
use Apretaste\Notifications;
use Framework\Database;
use Framework\GoogleAnalytics;

class Service
{
	/**
	 * Main function
	 *
	 * @param Request $request
	 * @param Response $response
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
	 * @param Request $request
	 * @param Response $response
	 * @return Response
	 * @throws \Apretaste\Alert
	 * @throws \Framework\Alert
	 * @throws \Kreait\Firebase\Exception\FirebaseException
	 * @throws \Kreait\Firebase\Exception\MessagingException
	 * @author salvipascual
	 */
	public function _canjear(Request $request, Response &$response)
	{
		// get coupon from the database
		$couponCode = Database::escape(strtoupper($request->input->data->coupon), 20);
		$coupon = Database::queryFirst("SELECT * FROM _cupones WHERE coupon = '$couponCode' AND active=1");
		$survey = null;
		$campaignCoupon = false;

		// response types
		$responseUsed = function() use (&$response, $couponCode) {
			return $response->setTemplate('message.ejs', [
				'header' => 'El cupón ya fue usado',
				'icon' => 'sentiment_very_dissatisfied',
				'text' => "Lo sentimos, pero el cupón insertado ($couponCode) ya fue usado por usted, y solo puede aplicarse una vez por usuario."
			]);
		};

		$responseExpired = function() use (&$response, $couponCode) {
			return $response->setTemplate('message.ejs', [
				'header' => 'El cupón ha expirado',
				'icon' => 'sentiment_very_dissatisfied',
				'text' => "Lo sentimos, pero el cupón insertado ($couponCode) ha expirado y no puede ser usado."
			]);
		};

		$responseNotFound = function() use (&$response, $couponCode) {
			return $response->setTemplate('message.ejs', [
				'header' => 'El cupón no existe',
				'icon' => 'sentiment_very_dissatisfied',
				'text' => "El cupón insertado ($couponCode) no existe o se encuentra desactivado. Por favor revise su cupón e intente nuevamente."
			]);
		};

		$responseMax = function() use (&$response, $couponCode) {
			return $response->setTemplate('message.ejs', [
				'header' => 'El cupón ha alcanzado su máximo',
				'icon' => 'sentiment_very_dissatisfied',
				'text' => "Este cupón ($couponCode) ha sido usado demasidas veces y ahora se encuentra desactivado."
			]);
		};

		$responseNotApplicable = function() use (&$response, $couponCode) {
			return $response->setTemplate('message.ejs', [
				'header' => 'El cupón no aplica',
				'icon' => 'sentiment_very_dissatisfied',
				'text' => "Lo sentimos, pero el cupón insertado ($couponCode) solo puede aplicarse a nuevos usuarios."
			]);
		};

		$responseCompleteSurvey = function() use (&$response, $couponCode, &$survey) {
			return $response->setTemplate('message.ejs', [
				'header' => 'Debe completar una encuesta antes',
				'icon' => 'sentiment_very_dissatisfied',
				'text' => "Para canjear el cupón $couponCode debe completar la encuesta <a href=\"#!\" onclick=\"apretaste.send({'command':'ENCUESTA VER', data: {id: '{$survey->id}'}});\">{$survey->title}</a>"
			]);
		};

		$responseUnexpectedError = function() use (&$response, $couponCode) {
			return $response->setTemplate('message.ejs', [
				'header' => 'Error inesperado',
				'icon' => 'sentiment_very_dissatisfied',
				'text' => 'Hemos encontrado un error. Por favor intente nuevamente, si el problema persiste, escríbanos al soporte.'
			]);
		};

		$responseSuccess = function() use (&$response, $couponCode, &$coupon) {
			return $response->setTemplate('message.ejs', [
				'header' => '¡Felicidades!',
				'icon' => 'sentiment_very_satisfied',
				'text' => "Su cupón se ha canjeado correctamente y usted ha ganado §{$coupon->prize_credits} en créditos de Apretaste. Gracias por canjear su cupón."
			]);
		};

		// check if coupon cannot be found
		if (empty($coupon)) {

			// campaign/individual coupons?
			$coupon = Database::queryFirst("
						SELECT *, 1 as prize_credits,
						       IF(coupon_used is NULl,0,1) used, 
						       0 as expired 
						FROM campaign_processed 
						WHERE coupon = '$couponCode' 
						  AND person_id = {$request->person->id}");

			// is a individual coupon
			if (!empty($coupon)) {
				$campaignCoupon = true;

				// used coupon
				if ((int) $coupon->used === 1) {
					return $responseUsed();
				}
			} else {
				return $responseNotFound();
			}
		}

		if (!$campaignCoupon)
		{
			// check if the coupon has been used already by the user
			$used = Database::query("SELECT COUNT(id) AS used FROM _cupones_used WHERE person_id='{$request->person->id}' AND coupon='$couponCode'")[0]->used;
			if ($used) {
				return $responseUsed();
			}

			// check if the coupon reached the usage limit
			if ($coupon->rule_limit) {
				$cnt = Database::query("SELECT COUNT(id) AS cnt FROM _cupones_used WHERE coupon='$couponCode'")[0]->cnt;
				if ($coupon->rule_limit <= $cnt) {
					return $responseMax();
				}
			}

			// check if the new user rule can be applied
			if ($coupon->rule_new_user) {
				$newUser = Database::query("SELECT COUNT(email) AS newuser FROM person WHERE email = '{$request->person->email}' AND DATEDIFF(NOW(), insertion_date) < 3")[0]->newuser;
				if (!$newUser) {
					return $responseNotApplicable();
				}
			}

			// check if the deadline rule can be applied
			if ($coupon->rule_deadline) {
				if (date('Y-m-d') > date('Y-m-d', strtotime($coupon->rule_deadline))) {
					return $responseExpired();
				}
			}

			// check for survey
			if (!empty(trim($coupon->survey))) {

				// search survey
				$survey = Database::queryFirst("SELECT * FROM _survey WHERE id = {$coupon->survey}");

				// maybe not exists
				if (!empty($survey)) {

					// search answers
					$surveyCompleted = Database::queryFirst("
							SELECT COUNT(*) AS cnt FROM _survey_done 
							WHERE survey_id = {$coupon->survey} AND person_id = {$request->person->id}")->cnt > 0;

					// if not completed
					if (!$surveyCompleted) {
						return $responseCompleteSurvey();
					}
				} else {
					$alert = new Alert('500', "Encuesta del cupon $couponCode no existe");
					$alert->post();
				}
			}
		}

		// duplicate if you are topacio level or higer
		if ($request->person->levelCode >= Level::TOPACIO) {
			$coupon->prize_credits *= 2;
		}

		// run powers for amulet CUPONESX2
		if (Amulets::isActive(Amulets::CUPONESX2, $request->person->id)) {
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
			return $responseUnexpectedError();
		}

		// set coupon as used
		if ($campaignCoupon) {
			Database::query("UPDATE campaign_processed SET coupon_used = now() WHERE campaign = {$coupon->campaign} AND coupon = '$couponCode' and person_id = {$request->person->id} LIMIT 1");
			Database::query("UPDATE campaign SET coupon_used = coupon_used + 1 WHERE id = {$coupon->campaign};");
		} else {
			Database::query("INSERT INTO _cupones_used (coupon, person_id) VALUES ('$couponCode', {$request->person->id})");
		}

		// add the experience
		Level::setExperience('COUPON_EXCHANGE', $request->person->id);

		// complete the challenge
		Challenges::complete('cupon', $request->person->id);

		// submit to Google Analytics
		if ($campaignCoupon) {
			GoogleAnalytics::event('cupon_complete', 'CAMPAIGN'.$coupon->campaign);
		} else {
			GoogleAnalytics::event('cupon_complete', $couponCode);
		}

		// offer rewards response
		return $responseSuccess();
	}
}
