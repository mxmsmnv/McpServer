<?php namespace ProcessWire;

/**
 * Admin process assets and installation lifecycle.
 */
trait ProcessMcpLifecycleTrait {

    public function init(): void {
        parent::init();
        $this->wire('config')->styles->add($this->wire('config')->urls->siteModules . 'McpServer/src/Admin/admin.css?v=' . McpServer::VERSION);
    }

    public function ___install(): void {
        parent::___install();
        $permissions = $this->wire('permissions');
        if(!$permissions->get('mcp-server-admin')->id) {
            $permission = new Permission();
            $permission->name = 'mcp-server-admin';
            $permission->title = 'Administer MCP Server';
            $permission->save();
        }
    }

}
