<?php

namespace Illuminate\Routing;

class RouteTree
{
    /**
     * The root node of the trie.
     *
     * @var \Illuminate\Routing\RouteTreeNode
     */
    protected $root;

    public function __construct()
    {
        $this->root = new RouteTreeNode;
    }

    /**
     * Insert a route into the trie.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return void
     */
    public function add(Route $route)
    {
        $segments = $this->segmentize($route->uri());

        $this->insert($this->root, $segments, 0, $route);
    }

    /**
     * Return candidate routes whose structure can match the given URI path.
     *
     * The caller is still responsible for running Route::matches() on each
     * candidate to apply scheme, host, regex, and method validators.
     *
     * @param  string  $path  Decoded path without a leading slash (e.g. "users/42")
     * @return \Illuminate\Routing\Route[]
     */
    public function getCandidates(string $path): array
    {
        $segments = $this->segmentize($path);

        $candidates = $this->search($this->root, $segments, 0);

        // Deduplicate (optional params may insert the same route more than once).
        $seen = [];
        $unique = [];

        foreach ($candidates as $route) {
            $id = spl_object_id($route);

            if (! isset($seen[$id])) {
                $seen[$id] = true;
                $unique[] = $route;
            }
        }

        return $unique;
    }

    /**
     * Split a URI / path string into non-empty segments.
     *
     * @param  string  $uri
     * @return string[]
     */
    protected function segmentize(string $uri): array
    {
        $uri = trim($uri, '/');

        if ($uri === '') {
            return [];
        }

        return explode('/', $uri);
    }

    /**
     * Recursively insert a route into the trie at the correct node.
     *
     * @param  \Illuminate\Routing\RouteTreeNode  $node
     * @param  string[]  $segments
     * @param  int  $index
     * @param  \Illuminate\Routing\Route  $route
     * @return void
     */
    protected function insert(RouteTreeNode $node, array $segments, int $index, Route $route)
    {
        if ($index >= count($segments)) {
            $node->routes[] = $route;

            return;
        }

        $segment = $segments[$index];

        if ($this->isWildcard($segment)) {
            // Catch-all / greedy parameter — matches everything from here onwards.
            if ($node->wildcardChild === null) {
                $node->wildcardChild = new RouteTreeNode;
            }

            $node->wildcardChild->routes[] = $route;
        } elseif ($this->isOptionalParam($segment)) {
            // Optional parameter: route is reachable both with and without this segment.
            $node->routes[] = $route;

            if ($node->paramChild === null) {
                $node->paramChild = new RouteTreeNode;
            }

            $this->insert($node->paramChild, $segments, $index + 1, $route);
        } elseif ($this->isParam($segment)) {
            // Required parameter: matches any single path segment.
            if ($node->paramChild === null) {
                $node->paramChild = new RouteTreeNode;
            }

            $this->insert($node->paramChild, $segments, $index + 1, $route);
        } else {
            // Static segment: only matches the exact literal.
            if (! isset($node->staticChildren[$segment])) {
                $node->staticChildren[$segment] = new RouteTreeNode;
            }

            $this->insert($node->staticChildren[$segment], $segments, $index + 1, $route);
        }
    }

    /**
     * Recursively collect candidate routes that structurally match the given segments.
     *
     * Static matches are returned before parameter matches so that more-specific
     * routes surface first, matching the expected routing precedence.
     *
     * @param  \Illuminate\Routing\RouteTreeNode  $node
     * @param  string[]  $segments
     * @param  int  $index
     * @return \Illuminate\Routing\Route[]
     */
    protected function search(RouteTreeNode $node, array $segments, int $index): array
    {
        // End of path — routes stored on this node are terminal candidates.
        if ($index >= count($segments)) {
            return $node->routes;
        }

        $segment = $segments[$index];
        $candidates = [];

        // Static match first (most specific).
        if (isset($node->staticChildren[$segment])) {
            $candidates = array_merge(
                $candidates,
                $this->search($node->staticChildren[$segment], $segments, $index + 1)
            );
        }

        // Parameter match (less specific than a static literal).
        if ($node->paramChild !== null) {
            $candidates = array_merge(
                $candidates,
                $this->search($node->paramChild, $segments, $index + 1)
            );
        }

        // Wildcard / catch-all match (least specific — matches any remaining path).
        if ($node->wildcardChild !== null) {
            $candidates = array_merge($candidates, $node->wildcardChild->routes);
        }

        return $candidates;
    }

    /**
     * Determine whether a URI segment is a required parameter placeholder (e.g. {id}).
     *
     * @param  string  $segment
     * @return bool
     */
    protected function isParam(string $segment): bool
    {
        return substr($segment, 0, 1) === '{'
            && substr($segment, -1) === '}'
            && substr($segment, -2) !== '?}';
    }

    /**
     * Determine whether a URI segment is an optional parameter placeholder (e.g. {id?}).
     *
     * @param  string  $segment
     * @return bool
     */
    protected function isOptionalParam(string $segment): bool
    {
        return substr($segment, 0, 1) === '{'
            && substr($segment, -2) === '?}';
    }

    /**
     * Determine whether a URI segment is a greedy / wildcard placeholder (e.g. {path+}).
     *
     * @param  string  $segment
     * @return bool
     */
    protected function isWildcard(string $segment): bool
    {
        return substr($segment, 0, 1) === '{'
            && (substr($segment, -2) === '+}' || substr($segment, -2) === '*}');
    }
}
