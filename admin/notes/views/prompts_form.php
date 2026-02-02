<?php

declare(strict_types=0);
?>
<div class="card" id="form">
  <h2 style="margin:0 0 12px 0;">Prompts</h2>

  <form method="post">
    <input type="hidden" name="action" value="prompt_create" />
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
      <div>
        <label>Name</label>
        <input type="text" name="p_name" required placeholder="Herald: Digest News" />
      </div>
      <div>
        <label>Kind</label>
        <select name="p_kind">
          <option value="prompt">prompt</option>
          <option value="system">system</option>
          <option value="persona">persona</option>
          <option value="tool">tool</option>
          <option value="chain">chain</option>
        </select>
      </div>
    </div>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
      <div>
        <label>Tags (csv)</label>
        <input type="text" name="p_tags" placeholder="php, api, inbox, sqlite" />
      </div>
      <div>
        <label>Model hint</label>
        <input type="text" name="p_model" placeholder="gpt-oss:latest / gemma3:4b" />
      </div>
    </div>

    <div>
      <label>Version</label>
      <input type="text" name="p_version" placeholder="2025-12-27.1" />
    </div>

    <div>
      <label>Prompt body</label>
      <textarea name="p_body" required placeholder="Write the prompt..."></textarea>
    </div>

    <button type="submit">Save Prompt</button>
  </form>
</div>
