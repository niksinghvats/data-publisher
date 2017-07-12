<?php

/**
 * Open Data Repository Data Publisher
 * ODRBadRequest Exception
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Wrapper class to get Symfony to return a 400 error.
 */

namespace ODR\AdminBundle\Exception;


class ODRBadRequestException extends ODRException
{

    /**
     * @param string $message
     */
    public function __construct($message = '')
    {
        if ($message == '')
            $message = 'Malformed request syntax';

        parent::__construct($message, self::getStatusCode());
    }


    /**
     * @inheritdoc
     */
    public function getStatusCode()
    {
        return 400;
    }
}
