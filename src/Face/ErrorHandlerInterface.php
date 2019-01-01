<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2018-12-31
 * Time: 13:12
 */

namespace Inhere\Console\Face;

use Inhere\Console\Application;
use Inhere\Console\AbstractApplication;

/**
 * Interface ErrorHandlerInterface
 * @package Inhere\Console\Face
 */
interface ErrorHandlerInterface
{
    /**
     * @param \Throwable  $e
     * @param Application|AbstractApplication $app
     */
    public function handle(\Throwable $e, AbstractApplication $app);
}