<?php

namespace LEClient;

/**
 * Main LetsEncrypt Client class, works as a framework for the LEConnector, LEAccount, LEOrder and LEAuthorization classes.
 *
 * PHP version 5.2.0
 *
 * MIT License
 *
 * Copyright (c) 2018 Youri van Weegberg
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * @author     Youri van Weegberg <youri@yourivw.nl>
 * @copyright  2018 Youri van Weegberg
 * @license    https://opensource.org/licenses/mit-license.php  MIT License
 * @link       https://github.com/yourivw/LEClient
 * @since      Class available since Release 1.0.0
 */
class LEClient
{
	const LE_PRODUCTION = 'https://acme-v02.api.letsencrypt.org';
	const LE_STAGING = 'https://acme-staging-v02.api.letsencrypt.org';

	private $certificateKeys;
	private $accountKeys;

	private $connector;
	private $account;

	private $log;

	const LOG_OFF = 0;		// Logs no messages or faults, except Runtime Exceptions.
	const LOG_STATUS = 1;	// Logs only messages and faults.
	const LOG_DEBUG = 2;	// Logs messages, faults and raw responses from HTTP requests.

    /**
     * Initiates the LetsEncrypt main client.
     *
     * @param array		    $email	 			The array of strings containing e-mail addresses. Only used in this function when creating a new account.
     * @param string	    $acmeURL			ACME URL, can be string or one of predefined values: LE_STAGING or LE_PRODUCTION. Defaults to LE_STAGING.
     * @param int 		    $log				The level of logging. Defaults to no logging. LOG_OFF, LOG_STATUS, LOG_DEBUG accepted. Defaults to LOG_OFF. (optional)
     * @param string|array 	$certificateKeys 	The main directory in which all keys (and certificates), including account keys, are stored. Defaults to 'keys/'. (optional)
     *                                          Optional: array containing location of all certificate files. Required paths are public_key, private_key, order and certificate/fullchain_certificate (you can use both or only one of them)
     * @param string|array  $accountKeys 		The directory in which the account keys are stored. Is a subdir inside $certificateKeys. Defaults to '__account/'.(optional)
     *                                          Optional: array containing location of account private and public keys. Required paths are private_key, public_key.
     */
	public function __construct($email, $acmeURL = LEClient::LE_STAGING, $log = LEClient::LOG_OFF, $certificateKeys = 'keys/', $accountKeys = '__account/')
	{

		$this->log = $log;

		if (!is_string($acmeURL))
		{
            throw new \RuntimeException('acmeURL must be set to string');
		}

		if (is_array($certificateKeys) && is_string($accountKeys)) throw new \RuntimeException('When certificateKeys is array, accountKeys must be array too.');
		elseif (is_array($accountKeys) && is_string($certificateKeys)) throw new \RuntimeException('When accountKeys is array, certificateKeys must be array too.');

		if (is_string($certificateKeys))
		{
			$certificateKeysDir = $certificateKeys;

			if(!file_exists($certificateKeys))
			{
				mkdir($certificateKeys, 0755, true);
				LEFunctions::createhtaccess($certificateKeys);
			}

			$this->certificateKeys = $this->buildKeysObj($certificateKeys);
		}
		elseif (is_array($certificateKeys))
		{
			if (!isset($certificateKeys['certificate']) && !isset($certificateKeys['fullchain_certificate'])) throw new \RuntimeException('certificateKeys[certificate] or certificateKeys[fullchain_certificate] file path must be set.');
			if (!isset($certificateKeys['private_key'])) throw new \RuntimeException('certificateKeys[private_key] file path must be set.');
			if (!isset($certificateKeys['order'])) $certificateKeys['order'] = dirname($certificateKeys['private_key']).'/order';
			if (!isset($certificateKeys['public_key'])) $certificateKeys['public_key'] = dirname($certificateKeys['private_key']).'/public.pem';

			foreach ($certificateKeys as $param => $file) {
				$parentDir = dirname($file);
				if (!is_dir($parentDir)) throw new \RuntimeException($parentDir.' directory not found.');
			}

			$this->certificateKeys = $certificateKeys;
		}
		else
		{
			throw new \RuntimeException('certificateKeys must be string or array.');
		}

		if (is_string($accountKeys))
		{
			$accountKeys = $certificateKeysDir.'/'.$accountKeys;

			if(!file_exists($accountKeys))
			{
				mkdir($accountKeys, 0755, true);
				LEFunctions::createhtaccess($accountKeys);
			}

			$this->accountKeys = array(
				"private_key" => $accountKeys.'/private.pem',
				"public_key" => $accountKeys.'/public.pem'
			);
		}
		elseif (is_array($accountKeys))
		{
			if (!isset($accountKeys['private_key'])) throw new \RuntimeException('accountKeys[private_key] file path must be set.');
			if (!isset($accountKeys['public_key'])) throw new \RuntimeException('accountKeys[public_key] file path must be set.');

			foreach ($accountKeys as $param => $file) {
				$parentDir = dirname($file);
				if (!is_dir($parentDir)) throw new \RuntimeException($parentDir.' directory not found.');
			}

			$this->accountKeys = $accountKeys;
		}
		else
		{
			throw new \RuntimeException('accountKeys must be string or array');
		}


		$this->connector = new LEConnector($this->log, $acmeURL, $this->accountKeys);
		$this->account = new LEAccount($this->connector, $this->log, $email, $this->accountKeys);
		
		if($this->log instanceof \Psr\Log\LoggerInterface) 
		{
			$this->log->info('LEClient finished constructing');
		}
		elseif($this->log >= LECLient::LOG_STATUS) LEFunctions::log('LEClient finished constructing', 'function LEClient __construct');
	}


	private function buildKeysObj($certificateKeysDir) {
	    if (substr($certificateKeysDir, -1) !== DIRECTORY_SEPARATOR) {
	        $certificateKeysDir .= DIRECTORY_SEPARATOR;
        }

        return array(
            "public_key" => $certificateKeysDir . 'public.pem',
            "private_key" => $certificateKeysDir . 'private.pem',
            "certificate" => $certificateKeysDir . 'certificate.crt',
            "fullchain_certificate" => $certificateKeysDir . 'fullchain.crt',
            "order" => $certificateKeysDir . 'order'
        );
    }

    /**
     * Returns the LetsEncrypt account used in the current client.
	 *
	 * @return LEAccount	The LetsEncrypt Account instance used by the client.
     */
	public function getAccount()
	{
		return $this->account;
	}

    /**
     * Returns a LetsEncrypt order. If an order exists, this one is returned. If not, a new order is created and returned.
     *
     * @param string	    $basename	        The base name for the order. Preferable the top domain (example.org). Will be the directory in which the keys are stored. Used for the CommonName in the certificate as well.
     * @param array 	    $domains 	        The array of strings containing the domain names on the certificate.
     * @param string        $keyType 	        Type of the key we want to use for certificate. Can be provided in ALGO-SIZE format (ex. rsa-4096 or ec-256) or simple "rsa" and "ec" (using default sizes)
     * @param string        $notBefore	        A date string formatted like 0000-00-00T00:00:00Z (yyyy-mm-dd hh:mm:ss) at which the certificate becomes valid. Defaults to the moment the order is finalized. (optional)
     * @param string        $notAfter  	        A date string formatted like 0000-00-00T00:00:00Z (yyyy-mm-dd hh:mm:ss) until which the certificate is valid. Defaults to 90 days past the moment the order is finalized. (optional)
     * @param string|null   $certificateKeys    Set to override certificateKeys location for this order
     * @param bool          $OCSPMustStaple     Set true to use RFC7633 'OCSP must staple' extension in CSR
     * @return LEOrder	    The LetsEncrypt Order instance which is either retrieved or created.
     */
	public function getOrCreateOrder($basename, $domains, $keyType = 'rsa-4096', $notBefore = '', $notAfter = '', $certificateKeys = null, $OCSPMustStaple = false)
	{
	    if ($certificateKeys)
	    {
            if(!file_exists($certificateKeys))
            {
                mkdir($certificateKeys, 0755, true);
                LEFunctions::createhtaccess($certificateKeys);
            }

            $this->certificateKeys = $this->buildKeysObj($certificateKeys);
        }

		return new LEOrder($this->connector, $this->log, $this->certificateKeys, $basename, $domains, $keyType, $notBefore, $notAfter, $OCSPMustStaple);
	}
}
