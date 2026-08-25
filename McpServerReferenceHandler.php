<?php namespace ProcessWire;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Exception\ToolCallException;

/** Convert expected ProcessWire domain failures into safe MCP tool errors. */
final class McpServerReferenceHandler implements ReferenceHandlerInterface {
    private ReferenceHandler $handler;

    public function __construct() {
        $this->handler = new ReferenceHandler();
    }

    public function handle(ElementReference $reference, array $arguments): mixed {
        try {
            return $this->handler->handle($reference, $arguments);
        } catch(WireException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        }
    }
}
