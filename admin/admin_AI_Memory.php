<?php

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';

// Auth check
auth_session_start();
if (!auth_is_logged_in()) {
    header('Location: ' . APP_URL . '/auth/?action=verify');
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Memory Architecture - Agent Hive Admin</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0d1117;
            color: #e6edf3;
            line-height: 1.6;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        header {
            border-bottom: 1px solid #30363d;
            padding-bottom: 2rem;
            margin-bottom: 2rem;
        }
        h1 { color: #58a6ff; margin-bottom: 0.5rem; }
        .subtitle { color: #8b949e; font-size: 0.9rem; }
        
        .architecture-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 3rem;
        }
        
        .section {
            background: #161b22;
            border: 1px solid #30363d;
            border-radius: 8px;
            padding: 1.5rem;
        }
        
        .section h2 {
            color: #79c0ff;
            font-size: 1.1rem;
            margin-bottom: 1rem;
            border-bottom: 2px solid #30363d;
            padding-bottom: 0.5rem;
        }
        
        .section h3 {
            color: #a371f7;
            font-size: 0.95rem;
            margin-top: 1rem;
            margin-bottom: 0.5rem;
        }
        
        ul, ol {
            margin-left: 1.5rem;
            margin-bottom: 1rem;
        }
        
        li { margin-bottom: 0.5rem; }
        
        .code-block {
            background: #0d1117;
            border-left: 3px solid #79c0ff;
            padding: 1rem;
            margin: 1rem 0;
            font-family: 'Monaco', 'Courier New', monospace;
            font-size: 0.85rem;
            overflow-x: auto;
            border-radius: 4px;
        }
        
        .highlight {
            background: #388bfd20;
            padding: 0.2rem 0.4rem;
            border-radius: 3px;
            color: #79c0ff;
        }
        
        .warning {
            background: #f85149;
            color: #fff;
            padding: 0.2rem 0.4rem;
            border-radius: 3px;
        }
        
        .success {
            background: #3fb950;
            color: #fff;
            padding: 0.2rem 0.4rem;
            border-radius: 3px;
        }
        
        .flow {
            background: #0d1117;
            border: 1px solid #30363d;
            border-radius: 6px;
            padding: 1.5rem;
            margin: 1rem 0;
        }
        
        .flow-step {
            display: flex;
            align-items: flex-start;
            margin-bottom: 1.5rem;
        }
        
        .flow-step:last-child { margin-bottom: 0; }
        
        .flow-number {
            background: #388bfd;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            flex-shrink: 0;
            margin-right: 1rem;
        }
        
        .flow-content { flex: 1; }
        
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: bold;
            margin-right: 0.5rem;
        }
        
        .status-todo { background: #f0883e; color: white; }
        .status-progress { background: #388bfd; color: white; }
        .status-done { background: #3fb950; color: white; }
        
        .comparison {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin: 1rem 0;
        }
        
        .comparison > div {
            padding: 1rem;
            border-radius: 6px;
        }
        
        .bad { background: #da3633; color: white; }
        .good { background: #3fb950; color: white; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🧠 AI Memory Architecture</h1>
            <p class="subtitle">RAG Pattern & Hallucination Prevention for Legal-Scale Document Analysis</p>
        </header>

        <div class="architecture-grid">
            <div class="section">
                <h2>🎯 Core Challenge</h2>
                <p>When analyzing large document sets (20,000+ pages), AI models hallucinate:</p>
                <ul>
                    <li>Make up information not in documents</li>
                    <li>Invent citations</li>
                    <li>Fill gaps with "likely" information</li>
                    <li>Merge similar statements incorrectly</li>
                </ul>
                <p style="margin-top: 1rem; font-style: italic;">The Problem: "Don't let the model remember. Make it retrieve with proof."</p>
            </div>

            <div class="section">
                <h2>✅ The Solution</h2>
                <p><strong>Retrieval-Augmented Generation (RAG)</strong> with forced citation:</p>
                <ol>
                    <li>Embed all documents → vector storage</li>
                    <li>Query → vector search → retrieve top-k chunks only</li>
                    <li>LLM sees ONLY retrieved chunks (not corpus)</li>
                    <li>Force every answer to cite sources</li>
                </ol>
                <p style="margin-top: 1rem; color: #3fb950;"><strong>Result:</strong> Hallucinations collapse to near-zero</p>
            </div>
        </div>

        <div class="section">
            <h2>🏗️ Production-Grade Architecture</h2>
            
            <h3>Step 1: Truth Layer (Chunk + Embed)</h3>
            <div class="flow">
                <div class="flow-step">
                    <div class="flow-number">1</div>
                    <div class="flow-content">
                        <strong>Break documents into chunks</strong><br>
                        Size: 500–1000 tokens | Overlap: 10–20%
                        <div class="code-block">
doc_id: "audit_2018.pdf"
filename: "audit_2018.pdf"
page_number: 14
paragraph_number: 2
text: "The internal audit identified irregular vendor billing patterns"
vector_embedding: [0.234, -0.122, ...]
                        </div>
                    </div>
                </div>
                <div class="flow-step">
                    <div class="flow-number">2</div>
                    <div class="flow-content">
                        <strong>Store with metadata</strong><br>
                        Embedding models: Qwen3, Gemma, others (consistency matters more than choice)
                    </div>
                </div>
            </div>

            <h3>Step 2: Query → Retrieval (Evidence Set)</h3>
            <div class="flow">
                <div class="flow-step">
                    <div class="flow-number">1</div>
                    <div class="flow-content">
                        <strong>User Question:</strong> "What did the 2018 audit conclude about vendor fraud?"
                    </div>
                </div>
                <div class="flow-step">
                    <div class="flow-number">2</div>
                    <div class="flow-content">
                        <strong>Pipeline:</strong>
                        <div class="code-block">
embed(query)
  → similarity search (vector index)
  → return top 5–10 chunks (NOT 1, NOT 50)
                        </div>
                    </div>
                </div>
                <div class="flow-step">
                    <div class="flow-number">3</div>
                    <div class="flow-content">
                        <strong>Evidence Set:</strong> Each chunk includes source, page, and text
                    </div>
                </div>
            </div>

            <h3>Step 3: Hard Grounded Prompt</h3>
            <p style="color: #f85149; font-weight: bold;">⚠️ This is where most systems fail</p>
            <div class="code-block">
You must answer ONLY using the provided sources.
If the answer is not explicitly stated in the sources, say:
"Not found in the provided documents."

Every statement must cite its source in the format:
(doc: filename.pdf, page: X)

---
[Doc: audit_2018.pdf, Page 14]
"...The internal audit identified irregular vendor billing patterns..."

[Doc: audit_2018.pdf, Page 22]
"...Evidence of duplicate payments totaling $480,000..."
---

Question: What did the 2018 audit conclude about vendor fraud?
            </div>
            <p><strong>Result:</strong> Model cannot invent. Citations are verifiable.</p>

            <h3>Step 4: Why This Works</h3>
            <ul>
                <li>❌ Model CANNOT access training data</li>
                <li>❌ Model CANNOT access full corpus</li>
                <li>❌ Model CANNOT fabricate cited evidence</li>
                <li>✅ You can instantly verify each citation</li>
            </ul>
            <p style="margin-top: 1rem;"><strong>Conversion:</strong> From "generative predictor" → "evidence synthesizer"</p>
        </div>

        <div class="architecture-grid">
            <div class="section">
                <h2>❌ What Causes Hallucinations</h2>
                <ul>
                    <li>Whole corpus in context window</li>
                    <li>Summarization without retrieval</li>
                    <li>Irrelevant vector search results</li>
                    <li>Prompt allows outside knowledge</li>
                    <li>No permission to say "Not found"</li>
                </ul>
                <p style="margin-top: 1rem; font-style: italic;">LLMs always try to be helpful. Explicitly permit "I don't know."</p>
            </div>

            <div class="section">
                <h2>🔒 Critical Extra Layer</h2>
                <p><strong>Evidence Span Highlighting:</strong></p>
                <div class="code-block">
"The audit identified irregular billing."
(doc: audit_2018.pdf, page 14)

→ This indicates vendor fraud.
                </div>
                <p style="margin-top: 1rem;">Separates source text from interpretation. Reduces legal risk dramatically.</p>
            </div>
        </div>

        <div class="section">
            <h2>🚀 Enterprise Pattern (Near-Zero Hallucination)</h2>
            <div class="flow">
                <div class="flow-step">
                    <div class="flow-number">1</div>
                    <div class="flow-content">Retrieve top-k chunks</div>
                </div>
                <div class="flow-step">
                    <div class="flow-number">2</div>
                    <div class="flow-content">Second-pass verification: "Is answer fully supported?"</div>
                </div>
                <div class="flow-step">
                    <div class="flow-number">3</div>
                    <div class="flow-content">If insufficient evidence → return "Not found" (retrieval + verification loop)</div>
                </div>
            </div>
        </div>

        <div class="section">
            <h2>🎓 Architectural Patterns</h2>
            
            <h3>Current Approach:</h3>
            <div class="comparison">
                <div class="bad">
                    <strong>❌ Naive (Hallucination-prone)</strong><br>
                    "Smart chatbot reading your case files"<br>
                    → Accesses full corpus<br>
                    → Generates from memory
                </div>
                <div class="good">
                    <strong>✅ Correct (Deterministic)</strong><br>
                    "Deterministic document retrieval + language layer"<br>
                    → Vector search only<br>
                    → Grounded synthesis
                </div>
            </div>

            <h3>Next-Level: Extraction over Interpretation</h3>
            <p>Instead of: <span class="warning">"What did the audit conclude?"</span></p>
            <p>Ask: <span class="success">"Extract all sentences mentioning vendor fraud"</span></p>
            <p style="margin-top: 1rem; color: #79c0ff;">Extraction → Interpretation (safer for legal environments)</p>
        </div>

        <div class="section" style="background: #1f6feb20; border-color: #388bfd; margin-top: 2rem;">
            <h2>📋 Implementation Roadmap</h2>
            <ul>
                <li><span class="status-badge status-todo">TODO</span> SQLite + embedding schema design</li>
                <li><span class="status-badge status-todo">TODO</span> Vector search integration</li>
                <li><span class="status-badge status-todo">TODO</span> RAG prompt framework</li>
                <li><span class="status-badge status-todo">TODO</span> Citation verification system</li>
                <li><span class="status-badge status-todo">TODO</span> Hallucination detection validator</li>
                <li><span class="status-badge status-todo">TODO</span> Evidence span highlighter</li>
            </ul>
        </div>
    </div>
</body>
</html>
