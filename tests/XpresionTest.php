<?php


namespace tests;


use Xpresion\Xpresion;

class XpresionTest extends \PHPUnit_Framework_TestCase {


    public function setUp()
    {
        Xpresion::init(true);
        parent::setUp();
    }

    /**
     * @dataProvider xpresionProvider
     */
    public function testXpresions($xpresion, $evaluation, $result, $data = null )
    {
        $debug = Xpresion::_($xpresion)->debug($data);
        $res = Xpresion::_($xpresion)->evaluate($data);
        $this->assertEquals($evaluation, $debug['evaluator']);
        $this->assertEquals($result, $res);
    }

    /**
     * @dataProvider timeExpresionProvider
     */
    public function testTimeXpresions($xpresion, $evaluation, $type, $data = null )
    {
        $debug = Xpresion::_($xpresion)->debug();
        $res = Xpresion::_($xpresion)->evaluate($data);
        $this->assertEquals($evaluation, $debug['evaluator']);
        $this->assertNotNull($res);
        $this->assertInternalType($type, $res);
    }

    public function xpresionProvider()
    {
        return array(
            array('13', '13', 13),
            array('1.32', '1.32', 1.32),
            array('-0.12', '(-0.12)', -0.12),
            array('-3', '(-3)', -3),
            array('("1,2,3")+3','("1,2,3".strval(3))', '1,2,33'),
            array('"1,2,3"+3','("1,2,3".strval(3))', '1,2,33'),
            array('"1,2,3"+3+4', '(("1,2,3".strval(3)).strval(4))', '1,2,334'),
            array('[1,2,3]+3', '$Fn->ary_merge(array(1,2,3),3)', array(1,2,3,3)),
            array('-3+2', '((-3)+2)', -1),
            array('1-3+2', '((1-3)+2)', 1-3+2),
            array('1+-3', '(1+(-3))', 1+-3),
            array('+1+3',  '(1+3)', +1+3),
            array('2*-1',  '(2*(-1))', 2*-1),
            array('2*(-1)', '(2*(-1))', 2*(-1)),
            array('2^-1', 'pow(2,(-1))', pow(2,(-1))),
            array('2^(-1)', 'pow(2,(-1))', pow(2,(-1))),
            array('2^-1^3', 'pow(2,pow((-1),3))', pow(2,pow((-1),3))),
            array('-2^-1^3', 'pow((-2),pow((-1),3))', pow((-2),pow((-1),3))),
            array('2^(-1)^3', 'pow(2,pow((-1),3))', pow(2,pow((-1),3))),
            array('$v', '$Var["v"]', 1, array('v' => 1)),
            array('True', 'true', true),
            array('"string"', '"string"', 'string'),
            array('["a","rra","y"]', 'array("a","rra","y")', array("a", "rra", "y")),
            array('`^regex?`i', '$Cache->re_1', '`^regex?`i'),
            array('0 == 1', '(0==1)', false),
            array('TRUE == False', '(true==false)', false),
            array('TRUE is False', '(true===false)', false),
            array('1+2', '(1+2)', 3),
            array('1+2+3', '((1+2)+3)', 1+2+3),
            array('1+2*3', '(1+(2*3))', 1+2*3),
            array('1*2+3', '((1*2)+3)', 1*2+3),
            array('1*2*3', '((1*2)*3)', 1*2*3 ),
            array('1+2/3', '(1+(2/3))', 1+2/3),
            array('1*2/3', '((1*2)/3)', 1*2/3),
            array('1^2', 'pow(1,2)', pow(1,2)),
            array('1^2^3', 'pow(1,pow(2,3))', pow(1,pow(2,3))),
            array('1^(2^3)', 'pow(1,pow(2,3))', pow(1,pow(2,3))),
            array('(1^2)^3', 'pow(pow(1,2),3)', pow(pow(1,2),3)),
            array('((1^2))^3', 'pow(pow(1,2),3)', pow(pow(1,2),3)),
            array('`^string?`i matches "string"', '$Fn->match("string",$Cache->re_1)', true),
            array('`^regex?`i matches "string"', '$Fn->match("string",$Cache->re_1)', false),
            array('`^string$`i matches "string" and `^string$`i matches "string2"', '($Fn->match("string",$Cache->re_1)&&$Fn->match("string2",$Cache->re_1))', false),
            array('$v in ["a","b","c"]', '$Fn->contains(array("a","b","c"),$Var["v"])', true, array('v' => 'a')),
            array('$v in ["a","b","c"]', '$Fn->contains(array("a","b","c"),$Var["v"])', false, array('v' => 'd')),
            array('1 ? (1+2) : (3+4)', '(1?(1+2):(3+4))', 3),
            array('1 ? sum(1,2) : (3+4)', '(1?$Fn->sum(1,2):(3+4))', 3),
            array('1 ? (2+3) : 2 ? (3+4) : (4+5)', '(1?(2+3):(2?(3+4):(4+5)))', 5),
            array('date("Y-m-d H:i:s", $time)', 'date("Y-m-d H:i:s",$Var["time"])', '2015-07-08 16:11:36', array('time' => 1436364696)),
            array('pow(1,pow(2,3))', 'pow(1,pow(2,3))', pow(1,pow(2,3))),
            array('pow(pow(2,3),4)', 'pow(pow(2,3),4)', pow(pow(2,3),4)),
            array('pow(pow(1,2),pow(2,3))', 'pow(pow(1,2),pow(2,3))', pow(pow(1,2),pow(2,3))),
        );
    }

    public function timeExpresionProvider()
    {
        return array(
            array('time()', 'time()', 'int'),
            array('date("Y-m-d H:i:s", time())', 'date("Y-m-d H:i:s",time())', 'string'),
        );
    }
}