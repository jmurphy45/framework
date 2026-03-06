<?php

namespace Illuminate\Routing;

class RouteTreeNode
{
    /**
     * Static children keyed by path segment literal.
     *
     * @var array<string, \Illuminate\Routing\RouteTreeNode>
     */
    public $staticChildren = [];

    /**
     * Child node for a parameterized segment (e.g. {id}, {user?}).
     *
     * @var \Illuminate\Routing\RouteTreeNode|null
     */
    public $paramChild = null;

    /**
     * Child node for catch-all / wildcard segments (e.g. {path+}).
     *
     * @var \Illuminate\Routing\RouteTreeNode|null
     */
    public $wildcardChild = null;

    /**
     * Routes that terminate at this node.
     *
     * @var \Illuminate\Routing\Route[]
     */
    public $routes = [];
}
