<?php namespace ProcessWire;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\ToolCallException;

/** Internal tool dispatcher: require gateway policy before invoking a provider. */
final class McpServerReferenceHandler implements ReferenceHandlerInterface {
    private ReferenceHandler $handler;

    public function __construct(private readonly \Closure $dispatch) {
        $this->handler = new ReferenceHandler();
    }

    public function handle(ElementReference $reference, array $arguments): mixed {
        if(!$reference instanceof ToolReference) throw new ToolCallException('Only registered tool references are supported.');
        try {
            return ($this->dispatch)($reference, $arguments, $this->handler);
        } catch(WireException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        }
    }
}
