<?php
/**
 * Created by PhpStorm.
 * User: itprofessor02
 * Date: 12.03.19
 * Time: 17:30
 */

use rollun\dic\InsideConstruct;
use rollun\logger\LifeCycleToken;
use service\Entity\Api\DataStore\Shipping\AllCosts;

error_reporting(E_ALL ^ E_USER_DEPRECATED ^ E_DEPRECATED); //E_ALL ^ E_USER_DEPRECATED

chdir(dirname(__DIR__));
require 'vendor/autoload.php';

/** @var \Interop\Container\ContainerInterface $container */
$container = require 'config/container.php';
InsideConstruct::setContainer($container);

$lifeCycleToken = LifeCycleToken::generateToken();
$container->setService(LifeCycleToken::class, $lifeCycleToken);

/** @var AllCosts $allCosts */
$allCosts = $container->get(AllCosts::class);

//$zipOrigination, $zipDestination, $pounds, $width, $length, $height, $quantity = null
//$query = $allCosts->buildUspShippingQuery('10001', '00601', 0.6, 10, 8, 1, 1);
//$result = $allCosts->query($query);
//
//echo '<pre>';
//print_r($result);
//die();