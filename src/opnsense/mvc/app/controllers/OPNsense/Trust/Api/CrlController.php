<?php

/*
 * Copyright (C) 2024 Deciso B.V.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

namespace OPNsense\Trust\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Base\UserException;
use OPNsense\Core\Config;
use OPNsense\Trust\Store as CertStore;

/**
 * Class CrlController
 * @package OPNsense\Trust\Api
 */
class CrlController extends ApiControllerBase
{
    private static $status_codes = [
        '0' => 'unspecified',
        '1' => 'keyCompromise',
        '2' => 'cACompromise',
        '3' => 'affiliationChanged',
        '4' => 'superseded',
        '5' => 'cessationOfOperation',
        '6' => 'certificateHold',
    ];

    public function searchAction()
    {
        $this->sessionClose();
        $config = Config::getInstance()->object();
        $items = [];
        foreach ($config->ca as $node) {
            $items[(string)$node->refid] =  ['descr' => (string)$node->descr, 'refid' =>  (string)$node->refid];
        }
        foreach ($config->crl as $node) {
            if (isset($items[(string)$node->caref])) {
                $items[(string)$node->caref]['crl_descr'] = (string)$node->descr;
            }
        }
        return $this->searchRecordsetBase(array_values($items));
    }

    /**
     * fetch (a new) revocation list for a given autority.
     */
    public function getAction($caref)
    {
        if ($this->request->isGet() && !empty($caref)) {
            $config = Config::getInstance()->object();
            $found = false;
            foreach ($config->ca as $node) {
                if ((string)$node->refid == $caref) {
                    $found = true;
                    break;
                }
            }
            if ($found) {
                $result = ['caref' => $caref, 'descr' => ''];
                foreach ($config->crl as $node) {
                    if ((string)$node->caref == $caref) {
                        $result['descr'] = (string)$node->descr;
                    }
                }
                $certs = [];
                foreach ($config->cert as $node) {
                    if ((string)$node->caref == $caref) {
                        $certs[(string)$node->refid] = [
                            'code' => null,
                            'descr' => (string)$node->descr
                        ];
                    }
                }
                $crlmethod = 'internal';
                foreach ($config->crl as $node) {
                    if ((string)$node->caref == $caref) {
                        foreach ($node->cert as $cert) {
                            if (!empty((string)$cert->refid)) {
                                $certs[(string)$cert->refid] = [
                                    'code' => (string)$cert->reason == '-1' ? '0' : (string)$cert->reason,
                                    'descr' => (string)$cert->descr
                                ];
                            }
                        }
                        $crlmethod = (string)$node->crlmethod;
                        $result['text'] = !empty((string)$node->text) ? base64_decode((string)$node->text) : '';
                    }
                }
                $result['crlmethod'] = [
                    'internal' => [
                        'value' => gettext('Internal'),
                        'selected' => $crlmethod == 'internal' ? '1' : '0'
                    ],
                    'existing' => [
                        'value' => gettext('Import existing'),
                        'selected' => $crlmethod == 'existing' ? '1' : '0'
                    ],
                ];
                for ($i = 0; $i < count($status_codes); $i++) {
                    $code = (string)$i;
                    $result['revoked_reason_' . $code] = [];
                    foreach ($certs as $ref => $data) {
                        $result['revoked_reason_' . $code][$ref] = [
                            'value' => $data['descr'],
                            'selected' => $data['code'] === $code ? '1' : '0'
                        ];
                    }
                }

                return ['crl' => $result];
            }
            return ['caref' => '', 'descr' => ''];
        }
    }

    /**
     * set crl for a certificate authority, mimicking standard model operations
     * (which we can not use due to the nested structure of the CRL's)
     */
    public function setAction($caref)
    {
        if ($this->request->isPost() && !empty($caref)) {
            Config::getInstance()->lock();
            $config = Config::getInstance()->object();
            $payload = $_POST['crl'] ?? [];
            $validations = [];
            if (!in_array($payload['crlmethod'], ['internal', 'existing'])) {
                $validations['crl.crlmethod'] = [sprintf(gettext('Invalid method %s'), $payload['crlmethod'])];
            }
            if (!preg_match('/^(.){1,255}$/', $payload['descr'] ?? '')) {
                $validations['crl.descr'] = gettext('Description should be a string between 1 and 255 characters.');
            }
            $found = false;
            foreach ($config->ca as $node) {
                if ((string)$node->refid == $caref) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $validations['crl.caref'] = gettext('Certificate does not seem to exist');
            }

            if (!empty($validations)) {
                return ['status' => 'failed', 'validations' => $validations];
            } else {
                $revoked_refs = [];
                for ($i = 0; $i < count($status_codes); $i++) {
                    $fieldname = 'revoked_reason_' . (string)$i;
                    foreach (explode(',', $payload[$fieldname] ?? '') as $refid) {
                        if (!empty($refid)) {
                            $revoked_refs[$refid] = (string)$i;
                        }
                    }
                }
                $crl = null;
                foreach ($config->crl as $node) {
                    if ((string)$node->caref == $caref) {
                        if ($crl !== null) {
                            /* When duplicate CRL's exist, remove all but the first */
                            $dom = dom_import_simplexml($node);
                            $dom->parentNode->removeChild($dom);
                        } else {
                            $crl = $node;
                        }
                    }
                }
                $last_crl = null;
                if ($crl === null) {
                    $last_crl = current($config->xpath('//opnsense/crl[last()]'));
                    if ($last_crl) {
                        $crl = simplexml_load_string('<crl/>');
                    } else {
                        $crl = $config->addChild('crl');
                    }
                }
                $crl->caref = (string)$caref;
                $crl->descr = (string)$payload['descr'];
                foreach ($crl->cert as $cert) {
                    if (!isset($revoked_refs[(string)$cert->refid])) {
                        $dom = dom_import_simplexml($cert);
                        $dom->parentNode->removeChild($dom);
                    } else {
                        $cert->reason = $revoked_refs[(string)$cert->refid];
                        unset($revoked_refs[(string)$cert->refid]);
                    }
                }
                foreach ($config->cert as $cert) {
                    if (isset($revoked_refs[(string)$cert->refid])) {
                        $tmp = $crl->addChild('cert');
                        $tmp->refid = (string)$cert->refid;
                        $tmp->descr = (string)$cert->descr;
                        $tmp->caref = (string)$cert->caref;
                        $tmp->crt = (string)$cert->crt;
                        $tmp->prv = (string)$cert->prv;
                        $tmp->revoke_time = (string)time();
                        $tmp->reason = $revoked_refs[(string)$cert->refid];
                    }
                }

                if ($last_crl) {
                    /* insert new item after last crl */
                    $target = dom_import_simplexml($last_crl);
                    $insert = $target->ownerDocument->importNode(dom_import_simplexml($crl), true);
                    if ($target->nextSibling) {
                        $target->parentNode->insertBefore($insert, $target->nextSibling);
                    } else {
                        $target->parentNode->appendChild($insert);
                    }
                }
                Config::getInstance()->save();
                return ['status' => 'saved'];
            }
        }
        return ['status' => 'failed'];
    }
}
