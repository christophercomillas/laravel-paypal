<?php

//site: https://medium.com/justlaravel/how-to-integrate-paypal-payment-gateway-in-laravel-695063599449

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PayPal\Rest\ApiContext;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Api\Agreement;
use PayPal\Api\Payer;
use PayPal\Api\Plan;
use PayPal\Api\PaymentDefinition;
use PayPal\Api\PayerInfo;
use Carbon\Carbon;

class PaymentController extends Controller
{
    //
    public function __construct()
    {
        /** PayPal api context **/
        $paypal_conf = \Config::get('paypal');
        $this->_api_context = new ApiContext(new OAuthTokenCredential(
            $paypal_conf['client_id'],
            $paypal_conf['secret'])
        );
        $this->_api_context->setConfig($paypal_conf['settings']);
    }

    public function index()
    {
        return view('home');
    }

    public function payWithpaypal(Request $request)
    {
        $payer = new Payer();
        $payer->setPaymentMethod('paypal');

        $item_1 = new Item();

        $item_1->setName('Item 1') /** item name **/
            ->setCurrency('USD')
            ->setQuantity(1)
            ->setPrice($request->get('amount')); /** unit price **/

        $item_list = new ItemList();

        $item_list->setItems(array($item_1));
        
        $amount = new Amount();
        $amount->setCurrency('USD')
            ->setTotal($request->get('amount'));

        $transaction = new Transaction();
        $transaction->setAmount($amount)
            ->setItemList($item_list)
            ->setDescription('Your transaction description');

        $redirect_urls = new RedirectUrls();
        $redirect_urls->setReturnUrl(URL::route('status')) /** Specify return URL **/
            ->setCancelUrl(URL::route('status'));

        $payment = new Payment();
        $payment->setIntent('Sale')
            ->setPayer($payer)
            ->setRedirectUrls($redirect_urls)
            ->setTransactions(array($transaction));

        /** dd($payment->create($this->_api_context));exit; **/
        try 
        {
            $payment->create($this->_api_context);
        } 
        catch (\PayPal\Exception\PPConnectionException $ex) 
        {
            if (\Config::get('app.debug')) 
            {
                Session::put('error', 'Connection timeout');
                return Redirect::route('paywithpaypal');
            } 
            else 
            {
                Session::put('error', 'Some error occur, sorry for inconvenient');
                return Redirect::route('paywithpaypal');
            }
        }

        foreach ($payment->getLinks() as $link) 
        {
            if ($link->getRel() == 'approval_url') 
            {
                $redirect_url = $link->getHref();
                break;
            }
        }
        /** add payment ID to session **/
            Session::put('paypal_payment_id', $payment->getId());
        if (isset($redirect_url)) 
        {
            /** redirect to paypal **/
            return Redirect::away($redirect_url);
        }
        
        Session::put('error', 'Unknown error occurred');
            return Redirect::route('paywithpaypal');
    }

    public function getPaymentStatus()
    {
        /** Get the payment ID before session clear **/
        $payment_id = Session::get('paypal_payment_id');

        /** clear the session payment ID **/
        Session::forget('paypal_payment_id');
        if (empty(Input::get('PayerID')) || empty(Input::get('token'))) 
        {
            Session::put('error', 'Payment failed');
                return Redirect::route('/');
        }

        $payment = Payment::get($payment_id, $this->_api_context);

        $execution = new PaymentExecution();

        $execution->setPayerId(Input::get('PayerID'));

        /**Execute the payment **/
        $result = $payment->execute($execution, $this->_api_context);
        if ($result->getState() == 'approved') {
            Session::put('success', 'Payment success');
            return Redirect::route('/');
        }
        Session::put('error', 'Payment failed');
            return Redirect::route('/');
    }
}
