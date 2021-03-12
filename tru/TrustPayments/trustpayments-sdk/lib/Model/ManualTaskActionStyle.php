<?php
/**
 * Trust Payments SDK
 *
 * This library allows to interact with the Trust Payments payment service.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */


namespace TrustPayments\Sdk\Model;
use \TrustPayments\Sdk\ObjectSerializer;

/**
 * ManualTaskActionStyle model
 *
 * @category    Class
 * @description 
 * @package     TrustPayments\Sdk
 * @author      customweb GmbH
 * @license     http://www.apache.org/licenses/LICENSE-2.0 Apache License v2
 */
class ManualTaskActionStyle
{
    /**
     * Possible values of this enum
     */
    const _DEFAULT = 'DEFAULT';
    const PRIMARY = 'PRIMARY';
    const DANGER = 'DANGER';
    
    /**
     * Gets allowable values of the enum
     * @return string[]
     */
    public static function getAllowableEnumValues()
    {
        return [
            self::_DEFAULT,
            self::PRIMARY,
            self::DANGER,
        ];
    }
}


