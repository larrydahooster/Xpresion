<?php


namespace Xpresion;


class XpresionFunc extends XpresionOp
{
    public function __construct($input='', $output='', $otype=null, $priority=1, $arity=1, $associativity=null, $fixity=null)
    {
        parent::__construct(
            is_string($input) ? array($input, $arity) : $input,
            Xpresion::PREFIX,
            null !== $associativity ? $associativity : Xpresion::RIGHT,
            $priority,
            /*1, */
            $output,
            $otype,
            null !== $fixity ? $fixity : Xpresion::PREFIX
        );
        $this->type = Xpresion::T_FUN;
    }

    public function __destruct()
    {
        $this->dispose();
    }
}