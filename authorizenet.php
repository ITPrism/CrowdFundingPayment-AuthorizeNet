<?php
/**
 * @package      Crowdfunding
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2015 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

// no direct access
defined('_JEXEC') or die;

jimport("Prism.init");
jimport("Crowdfunding.init");
jimport("EmailTemplates.init");

/**
 * Crowdfunding AuthorizeNet Payment Plugin
 *
 * @package      Crowdfunding
 * @subpackage   Plugins
 */
class plgCrowdfundingPaymentAuthorizeNet extends Crowdfunding\Payment\Plugin
{
    protected $paymentService = "authorizenet";

    protected $textPrefix = "PLG_CROWDFUNDINGPAYMENT_AUTHORIZENET";
    protected $debugType = "AUTHORIZENET_PAYMENT_PLUGIN_DEBUG";

    /**
     * @var JApplicationSite
     */
    protected $app;

    protected $extraDataKeys = array(
        "x_response_reason_code", "x_response_reason_text", "x_trans_id", "x_method", "x_card_type", "x_account_number",
        "x_first_name", "x_last_name", "x_company", "x_address", "x_city", "x_state", "x_zip", "x_country",
        "x_type", "x_amount", "x_tax", "x_duty", "x_freight", "x_tax_exempt", "x_test_request", "custom"
    );

    /**
     * This method prepares a payment gateway - buttons, forms,...
     * That gateway will be displayed on the summary page as a payment option.
     *
     * @param string    $context This string gives information about that where it has been executed the trigger.
     * @param object    $item    A project data.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @return null|string
     */
    public function onProjectPayment($context, &$item, &$params)
    {
        if (strcmp("com_crowdfunding.payment", $context) != 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("html", $docType) != 0) {
            return null;
        }

        // This is a URI path to the plugin folder
        $pluginURI = "plugins/crowdfundingpayment/authorizenet";

        $apiLoginId     = Joomla\String\String::trim($this->params->get('authorizenet_login_id'));
        $transactionKey = Joomla\String\String::trim($this->params->get('authorizenet_transaction_key'));

        $html   = array();
        $html[] = '<div class="well">'; // Open "well".
        
        $html[] = '<h4><img src="' . $pluginURI . '/images/authorizenet_icon.png" width="50" height="32" alt="AuthorizeNet" />' . JText::_($this->textPrefix . "_TITLE") . '</h4>';

        // Check for error with configuration.
        if (!$apiLoginId or !$transactionKey) {
            $html[] = '<div class="alert">' . JText::_($this->textPrefix . "_ERROR_PLUGIN_NOT_CONFIGURED") . '</div>';
            return implode("\n", $html);
        }


        // Load the script that initialize the select element with banks.
        JHtml::_("jquery.framework");
        $doc->addScript($pluginURI . "/js/plg_crowdfundingpayment_authorizenet.js");

        $callbackUrl = $this->getCallbackUrl();
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_NOTIFY_URL"), $this->debugType, $callbackUrl) : null;

        // Get payment session

        $paymentSessionContext    = Crowdfunding\Constants::PAYMENT_SESSION_CONTEXT . $item->id;
        $paymentSessionLocal      = $this->app->getUserState($paymentSessionContext);

        $paymentSession = $this->getPaymentSession(array(
            "session_id"    => $paymentSessionLocal->session_id
        ));

        // Prepare custom data
        $custom = array(
            "payment_session_id" => $paymentSession->getId(),
            "gateway"            => "AuthorizeNet"
        );
        $custom = base64_encode(json_encode($custom));

        $keys = array(
            "api_login_id"    => $apiLoginId,
            "transaction_key" => $transactionKey
        );

        $authNet = new Prism\Payment\AuthorizeNet\Service\Dpm($keys);

        $description = JText::sprintf($this->textPrefix . "_INVESTING_IN_S", htmlentities($item->title, ENT_QUOTES, "UTF-8"));

        $authNet
            ->setAmount($item->amount)
            ->setCurrency($item->currencyCode)
            ->setDescription($description)
            ->setSequence($paymentSession->getId())
            ->setRelayUrl($callbackUrl)
            ->setType("AUTH_CAPTURE")
            ->setMethod("CC")
            ->setCustom($custom)
            ->enableRelayResponse();

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DPM_OBJECT"), $this->debugType, $authNet) : null;

        $html[] = '<button type="button" class="btn btn-default btn-xs" id="js-cfpayment-toggle-fields">' . JText::_($this->textPrefix . "_TOGGLE_FIELDS") . "</button>";

        if ($this->params->get("authorizenet_display_fields", 0)) {
            $html[] = '<div id="js-cfpayment-authorizenet">';
        } else {
            $html[] = '<div id="js-cfpayment-authorizenet" style="display: none;">';
        }

        if (!$this->params->get('authorizenet_sandbox', 1)) {
            $html[] = '<form action="' . Joomla\String\String::trim($this->params->get('authorizenet_url')) . '" method="post" autocomplete="off">';
            $authNet->disableTestMode();
        } else {
            $html[] = '<form action="' . Joomla\String\String::trim($this->params->get('authorizenet_sandbox_url')) . '" method="post" autocomplete="off">';
            $authNet->enableTestMode();
        }

        $hiddenFields = $authNet->getHiddenFields();

        $html = array_merge($html, $hiddenFields);

        $html[] = '<fieldset>';
        $html[] = '<div class="form-group">';
        $html[] = '     <label for="x_card_num">' . JText::_($this->textPrefix . "_CREDIT_CARD_NUMBER").'</label>';
        $html[] = '     <input type="text" name="x_card_num" id="x_card_num" value="" class="form-control" />';
        $html[] = '</div>';
        $html[] = '<div class="form-group">';
        $html[] = '     <label for="x_exp_date">' . JText::_($this->textPrefix . "_EXPIRES") . '</label>';
        $html[] = '     <input type="text" name="x_exp_date" value="" class="form-control" />';
        $html[] = '</div>';
        $html[] = '<div class="form-group">';
        $html[] = '     <label for="x_card_code">' . JText::_($this->textPrefix . "_CCV") . '</label>';
        $html[] = '     <div class="controls"><input type="text" name="x_card_code" value="" class="form-control" />';
        $html[] = '</div>';
        $html[] = '</fieldset>';

        $html[] = '<fieldset>';
        $html[] = '<div class="form-group">';
        $html[] = '     <label for="x_first_name">' . JText::_($this->textPrefix . "_FIRST_NAME") . '</label>';
        $html[] = '     <input type="text" name="x_first_name" value="" class="form-control" />';
        $html[] = '</div>';
        $html[] = '<div class="form-group">';
        $html[] = '     <label for="x_last_name">' . JText::_($this->textPrefix . "_LAST_NAME") . '</label>';
        $html[] = '     <input type="text" name="x_last_name" value="" class="form-control" />';
        $html[] = '</div>';
        $html[] = '</fieldset>';

        $html[] = '<fieldset>';
        $html[] = '<div class="form-group">';
        $html[] = '     <label for="x_address">' . JText::_($this->textPrefix . "_ADDRESS") . '</label>';
        $html[] = '     <input type="text" name="x_address" value="" class="form-control" />';
        $html[] = '</div>';
        $html[] = '<div class="form-group">';
        $html[] = '     <label for="x_city">' . JText::_($this->textPrefix . "_CITY") . '</label>';
        $html[] = '     <input type="text" name="x_city" value="" class="form-control" />';
        $html[] = '</div>';
        $html[] = '<div class="form-group">';
        $html[] = '     <label for="x_state">' . JText::_($this->textPrefix . "_STATE") . '</label>';
        $html[] = '     <input type="text" name="x_state" value="" class="form-control" />';
        $html[] = '</div>';
        $html[] = '<div class="form-group">';
        $html[] = '     <label for="x_zip">' . JText::_($this->textPrefix . "_ZIP_CODE") . '</label>';
        $html[] = '     <input type="text" name="x_zip" value="" class="form-control" />';
        $html[] = '</div>';
        $html[] = '<div class="form-group">';
        $html[] = '     <label for="x_country">' . JText::_($this->textPrefix . "_COUNTRY") . '</label>';
        $html[] = '     <input type="text" name="x_country" value="" class="form-control" />';
        $html[] = '</div>';
        $html[] = '</fieldset>';

        $html[] = '<input type="submit" value="' . JText::_($this->textPrefix . "_SUBMIT") . '" class="btn btn-primary">';

        $html[] = '</form>';

        if ($this->params->get('authorizenet_display_info', 1)) {
            $html[] = '<p class="bg-info p-10-5 mt-10"><span class="glyphicon glyphicon-info-sign"></span> ' . JText::_($this->textPrefix . "_INFO") . '</p>';
        }

        if ($this->params->get('authorizenet_sandbox', 1)) {
            $html[] = '<p class="bg-info p-10-5"><span class="glyphicon glyphicon-info-sign"></span> ' . JText::_($this->textPrefix . "_WORKS_SANDBOX") . '</p>';
        }

        $html[] = '</div>';

        $html[] = '</div>'; // Close "well".

        return implode("\n", $html);
    }

    /**
     * This method processes transaction data that comes from the paymetn gateway.
     *
     * @param string    $context This string gives information about that where it has been executed the trigger.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @return null|array
     */
    public function onPaymentNotify($context, &$params)
    {
        if (strcmp("com_crowdfunding.notify.authorizenet", $context) != 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("raw", $docType) != 0) {
            return null;
        }

        // Validate request method
        $requestMethod = $this->app->input->getMethod();
        if (strcmp("POST", $requestMethod) != 0) {
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_REQUEST_METHOD"),
                $this->debugType,
                JText::sprintf($this->textPrefix . "_ERROR_INVALID_TRANSACTION_REQUEST_METHOD", $requestMethod)
            );

            return null;
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RESPONSE"), $this->debugType, $_POST) : null;

        // Decode custom data
        $custom = Joomla\Utilities\ArrayHelper::getValue($_POST, "custom");
        $custom = json_decode(base64_decode($custom), true);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_CUSTOM"), $this->debugType, $custom) : null;

        // Verify gateway. Is it AuthorizeNet?
        $gateway = Joomla\Utilities\ArrayHelper::getValue($custom, "gateway");
        if (!$this->isValidPaymentGateway($gateway)) {
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_PAYMENT_GATEWAY"),
                $this->debugType,
                array("custom" => $custom, "_POST" => $_POST)
            );

            return null;
        }

        // Prepare the array that will be returned by this method
        $result = array(
            "project"         => null,
            "reward"          => null,
            "transaction"     => null,
            "payment_session" => null,
            "payment_service" => $this->paymentService
        );

        // Get currency
        $currencyId = $params->get("project_currency");
        $currency   = Crowdfunding\Currency::getInstance(JFactory::getDbo(), $currencyId, $params);

        // Get payment session.
        $paymentSessionId = Joomla\Utilities\ArrayHelper::getValue($custom, "payment_session_id", 0, "int");

        $paymentSession = $this->getPaymentSession(array("id" => $paymentSessionId));

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_PAYMENT_SESSION"), $this->debugType, $paymentSession->getProperties()) : null;

        // Validate transaction data
        $validData = $this->validateData($_POST, $currency->getCode(), $paymentSession);
        if (is_null($validData)) {
            return $result;
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_VALID_DATA"), $this->debugType, $validData) : null;

        // Get project
        $projectId = Joomla\Utilities\ArrayHelper::getValue($validData, "project_id");
        $project   = Crowdfunding\Project::getInstance(JFactory::getDbo(), $projectId);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_PROJECT_OBJECT"), $this->debugType, $project->getProperties()) : null;

        // Check for valid project
        if (!$project->getId()) {

            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_PROJECT"),
                $this->debugType,
                $validData
            );

            return $result;
        }

        // Set the receiver of funds
        $validData["receiver_id"] = $project->getUserId();

        // Save transaction data.
        // If it is not completed, return empty results.
        // If it is complete, continue with process transaction data
        $transactionData = $this->storeTransaction($validData, $project);
        if (is_null($transactionData)) {
            return $result;
        }

        // Update the number of distributed reward.
        $rewardId = Joomla\Utilities\ArrayHelper::getValue($transactionData, "reward_id");
        $reward   = null;
        if (!empty($rewardId)) {
            $reward = $this->updateReward($transactionData);

            // Validate the reward.
            if (!$reward) {
                $transactionData["reward_id"] = 0;
            }
        }

        //  Prepare the data that will be returned

        $result["transaction"] = Joomla\Utilities\ArrayHelper::toObject($transactionData);

        // Generate object of data based on the project properties
        $properties        = $project->getProperties();
        $result["project"] = Joomla\Utilities\ArrayHelper::toObject($properties);

        // Generate object of data based on the reward properties
        if (!empty($reward)) {
            $properties       = $reward->getProperties();
            $result["reward"] = Joomla\Utilities\ArrayHelper::toObject($properties);
        }

        // Generate data object, based on the payment session properties.
        $properties       = $paymentSession->getProperties();
        $result["payment_session"] = Joomla\Utilities\ArrayHelper::toObject($properties);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RESULT_DATA"), $this->debugType, $result) : null;

        // Remove payment session.
        $txnStatus = (isset($result["transaction"]->txn_status)) ? $result["transaction"]->txn_status : null;
        $this->closePaymentSession($paymentSession, $txnStatus);

        return $result;
    }

    /**
     * This method is executed after complete payment.
     * It is used to be sent mails to user and administrator.
     *
     * @param string $context
     * @param object $transaction Transaction data
     * @param Joomla\Registry\Registry  $params Component parameters
     * @param object $project Project data
     * @param object $reward Reward data
     * @param object $paymentSession Payment session data.
     *
     * @return null|array
     */
    public function onAfterPayment($context, &$transaction, &$params, &$project, &$reward, &$paymentSession)
    {
        if (strcmp("com_crowdfunding.notify.authorizenet", $context) != 0) {
            return;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("raw", $docType) != 0) {
            return;
        }

        // Send mails
        $this->sendMails($project, $transaction, $params);

        // Prepare return page
        $returnUrl = $this->getReturnUrl($project->slug, $project->catslug);

        // Log the URL.
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RETURN_URL"), $this->debugType, $returnUrl) : null;

        echo '<html><head><script>
        <!--
        window.location="' . $returnUrl . '";
        //-->
        </script>
        </head><body><noscript><meta http-equiv="refresh" content="1;url=' . $returnUrl . '"></noscript></body></html>';

    }

    /**
     * Validate transaction data.
     *
     * @param array  $data
     * @param string $currency
     * @param Crowdfunding\Payment\Session  $paymentSession
     *
     * @return null|array
     *
     * @todo It must be tested with international transaction ( currency other than USD ),
     * bacause the response in test mode does not return currency value ( x_currency_code ).
     */
    protected function validateData($data, $currency, $paymentSession)
    {
        $authResponse = new Prism\Payment\AuthorizeNet\Response($data);

        $apiLoginId = Joomla\String\String::trim($this->params->get("authorizenet_login_id"));
        $md5Setting = Joomla\String\String::trim($this->params->get("authorizenet_md5_hash"));

        $authResponse->setApiLoginId($apiLoginId);
        $authResponse->setMd5Setting($md5Setting);

        // Check for valid response.
        if (!$authResponse->isAuthorizeNet()) {

            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_RESPONSE"),
                $this->debugType,
                $authResponse
            );

            return null;
        }

        // Get date
        $date = new JDate();

        // Get currency
        $txnCurrency = (!$authResponse->getCurrency()) ? $currency : $authResponse->getCurrency();

        // If it is test mode, set fake transaction ID.
        if ($this->params->get("authorizenet_sandbox", 0)) {
            $transactionId = new Prism\String();
            $transactionId->generateRandomString(10, "TEST");

            $authResponse->setTransactionId((string)$transactionId);
            $txnCurrency = "USD";
        }

        // Prepare transaction data
        $transaction = array(
            "investor_id"      => (int)$paymentSession->getUserId(),
            "project_id"       => (int)$paymentSession->getProjectId(),
            "reward_id"        => ($paymentSession->isAnonymous()) ? 0 : (int)$paymentSession->getRewardId(),
            "service_provider" => "AuthorizeNet",
            "txn_id"           => $authResponse->getTransactionId(),
            "txn_amount"       => $authResponse->getAmount(),
            "txn_currency"     => $txnCurrency,
            "txn_date"         => $date->toSql(),
            "extra_data"       => $this->prepareExtraData($data)
        );

        if ($authResponse->isApproved()) {
            $transaction["txn_status"] = "completed";
        } else {
            $transaction["txn_status"] = "failed";
        }

        // Check Project ID and Transaction ID
        if (!$transaction["project_id"] or !$transaction["txn_id"]) {

            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_TRANSACTION_DATA"),
                $this->debugType,
                $transaction
            );

            return null;
        }

        // Check currency
        if (strcmp($transaction["txn_currency"], $currency) != 0) {

            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_TRANSACTION_CURRENCY"),
                $this->debugType,
                array("TRANSACTION DATA" => $transaction, "CURRENCY" => $currency)
            );

            return null;
        }

        return $transaction;
    }

    /**
     * Save transaction
     *
     * @param array               $transactionData
     * @param Crowdfunding\Project $project
     *
     * @return null|array
     */
    protected function storeTransaction($transactionData, $project)
    {
        // Get transaction by txn ID
        $keys        = array(
            "txn_id" => Joomla\Utilities\ArrayHelper::getValue($transactionData, "txn_id")
        );
        $transaction = new Crowdfunding\Transaction(JFactory::getDbo());
        $transaction->load($keys);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_TRANSACTION_OBJECT"), $this->debugType, $transaction->getProperties()) : null;

        // Check for existed transaction
        if ($transaction->getId()) {

            // If the current status if completed,
            // stop the process.
            if ($transaction->isCompleted()) {
                return null;
            }

        }

        // Store the new transaction data.
        $transaction->bind($transactionData);
        $transaction->store();

        // If it is not completed (it might be pending or other status),
        // stop the process. Only completed transaction will continue
        // and will process the project, rewards,...
        if (!$transaction->isCompleted()) {
            return null;
        }

        // Set transaction ID.
        $transactionData["id"] = $transaction->getId();

        // If the new transaction is completed,
        // update project funded amount.
        $amount = Joomla\Utilities\ArrayHelper::getValue($transactionData, "txn_amount");
        $project->addFunds($amount);
        $project->storeFunds();

        return $transactionData;
    }
}
