//! `phemc mcp`: the Model Context Protocol server of Phem, on stdin and stdout. It gives the
//! coding agents the texts of `phemc docs`, `components`, `examples` and `search`, and the check
//! of one file or of a code, as tools. Each tool runs the same code as its command.
//!
//! The transport is stdio: one JSON-RPC 2.0 message on each line. The logs go to stderr. The
//! server reads the project again for each call, so it gives the code of the moment.

use crate::{check_one, line_and_column};
use phemc_codegen::FileDiagnostics;
use phemc_knowledge::commands::{self, Format};
use phemc_knowledge::Knowledge;
use serde_json::{json, Map, Value};
use std::io::{BufRead, Write};
use std::panic::{catch_unwind, AssertUnwindSafe};
use std::path::{Component, Path, PathBuf};

/// The protocol versions that the server knows, the newest first.
const VERSIONS: &[&str] = &["2025-06-18", "2025-03-26", "2024-11-05"];

/// The names of the tools, in the order of `tools/list`.
const TOOLS: [&str; 6] = ["phem_search", "phem_doc", "phem_components", "phem_component", "phem_examples", "phem_check"];

/// What the agent reads at the start: the workflow.
const INSTRUCTIONS: &str = "Phem: `.phem` files are PHP with markup literals, for Symfony apps. \
Before you write `.phem` code, call phem_search with the task in a few words, then read the best keys with phem_doc, \
phem_component or phem_examples. Start from the closest example and use only the props and the parts that phem_component shows. \
After you write a file, call phem_check with its path and correct all the errors.";

/// Runs the server until stdin closes.
pub fn run(project: &Path, src: &Path) -> std::io::Result<()> {
    let mut server = Server { out: std::io::stdout(), project: project.to_path_buf(), src: src.to_path_buf() };

    server.serve(std::io::stdin().lock())
}

struct Server<W: Write> {
    out: W,
    /// The project root.
    project: PathBuf,
    /// The source directory of the app (`--src`).
    src: PathBuf,
}

impl<W: Write> Server<W> {
    /// Handles the lines of the input until it ends.
    fn serve(&mut self, mut input: impl BufRead) -> std::io::Result<()> {
        let mut line = Vec::new();

        loop {
            line.clear();
            if input.read_until(b'\n', &mut line)? == 0 {
                return Ok(());
            }

            // A line that is not UTF-8 is a parse error: the server goes on.
            match std::str::from_utf8(&line) {
                Ok(text) => self.handle(text),
                Err(_) => self.send(error(Value::Null, -32700, "parse error: the message is not UTF-8")),
            }
        }
    }

    /// Handles one line: a message, or a batch of messages (protocol version 2025-03-26).
    fn handle(&mut self, line: &str) {
        if line.trim().is_empty() {
            return;
        }

        let message: Value = match serde_json::from_str(line) {
            Ok(m) => m,
            Err(e) => return self.send(error(Value::Null, -32700, &format!("parse error: {e}"))),
        };

        let Value::Array(batch) = message else {
            if let Some(response) = self.respond(&message) {
                self.send(response);
            }

            return;
        };

        if batch.is_empty() {
            return self.send(error(Value::Null, -32600, "the batch is empty"));
        }

        // The responses of the requests of a batch, in one array.
        let responses: Vec<Value> = batch.iter().filter_map(|m| self.respond(m)).collect();
        if !responses.is_empty() {
            self.send(Value::Array(responses));
        }
    }

    /// The response to a message: a request gets one, a notification and a response get none.
    fn respond(&self, message: &Value) -> Option<Value> {
        let method = message.get("method").and_then(Value::as_str)?;
        let id = message.get("id")?;

        let params = message.get("params").cloned().unwrap_or(Value::Null);
        let response = match method {
            "initialize" => result(id, initialize(&params)),
            "ping" => result(id, json!({})),
            "tools/list" => result(id, json!({ "tools": tools() })),
            "tools/call" => match self.call(&params) {
                Ok(content) => result(id, content),
                Err((code, text)) => error(id.clone(), code, &text),
            },
            _ => error(id.clone(), -32601, &format!("unknown method `{method}`")),
        };

        Some(response)
    }

    fn send(&mut self, message: Value) {
        let _ = writeln!(self.out, "{message}");
        let _ = self.out.flush();
    }

    /// `tools/call`. An unknown tool is a protocol error. A wrong argument or a missing key is a
    /// tool error (`isError`): the agent reads it and tries again.
    fn call(&self, params: &Value) -> Result<Value, (i64, String)> {
        let name = params.get("name").and_then(Value::as_str).ok_or((-32602, "`name` is required".to_string()))?;
        let empty = Map::new();
        let args = match params.get("arguments") {
            None | Some(Value::Null) => &empty,
            Some(Value::Object(o)) => o,
            Some(_) => return Err((-32602, "`arguments` must be an object".into())),
        };

        if !TOOLS.contains(&name) {
            return Err((-32602, format!("unknown tool `{name}`")));
        }

        // A bug of phemc fails the call, not the server.
        let reply = match catch_unwind(AssertUnwindSafe(|| self.run_tool(name, args))) {
            Ok(Ok(reply)) => reply,
            Ok(Err(e)) => Reply { text: e, error: true },
            Err(_) => Reply { text: format!("phemc failed on this call of `{name}` (a bug of phemc: see the log of the server)"), error: true },
        };

        Ok(json!({ "content": [{ "type": "text", "text": reply.text }], "isError": reply.error }))
    }

    fn run_tool(&self, name: &str, args: &Map<String, Value>) -> Result<Reply, String> {
        let knowledge = |required: bool| crate::knowledge::load(&self.project, &self.src, required).map_err(|e| format!("{e:#}"));

        match name {
            "phem_search" => {
                let query = string(args, "query")?.ok_or("`query` is required")?;
                let limit = limit(args)?;

                Ok(commands::search(&knowledge(false)?, query, limit, Format::Md).into())
            }
            "phem_doc" => {
                let key = string(args, "key")?;
                let k = if commands::doc_needs_project(key) { knowledge(true)? } else { Knowledge::embedded() };

                // With no key: the map of the docs.
                Ok(commands::docs(&k, key, true, Format::Md).into())
            }
            "phem_components" => Ok(commands::components(&knowledge(true)?, None, string(args, "package")?, Format::Md).into()),
            "phem_component" => {
                let component = string(args, "name")?.ok_or("`name` is required")?;

                Ok(commands::components(&knowledge(true)?, Some(component), string(args, "package")?, Format::Md).into())
            }
            "phem_examples" => Ok(commands::examples(&knowledge(true)?, string(args, "name")?, string(args, "package")?, Format::Md).into()),
            // `call` checks the name.
            _ => self.check(string(args, "path")?, string(args, "code")?),
        }
    }

    /// `phem_check`: the diagnostics of a file of the project, or of a code in place of it.
    fn check(&self, path: Option<&str>, code: Option<&str>) -> Result<Reply, String> {
        if path.is_none() && code.is_none() {
            return Err("give `path`, `code`, or both".into());
        }

        // The check reads only the files of the project.
        let file = PathBuf::from(path.unwrap_or("snippet.phem"));
        if file.components().any(|c| !matches!(c, Component::Normal(_) | Component::CurDir)) {
            return Err("`path` must be a relative path in the project, with no `..`".into());
        }

        let (path, diags) = check_one(&self.project, &self.src, &file, code.map(String::from)).map_err(|e| format!("{e:#}"))?;

        Ok(Reply { text: check_text(&path, &diags), error: false })
    }
}

/// The text of a tool.
struct Reply {
    text: String,
    /// A tool error: a wrong argument, a missing key, or a project that cannot be read.
    error: bool,
}

impl From<commands::Answer> for Reply {
    fn from(a: commands::Answer) -> Self {
        Reply { text: a.text, error: !a.ok }
    }
}

/// The diagnostics of a checked file, one line each, with 1-based lines and columns: the ones
/// of `phemc check` (see `FileDiagnostics::printed()`).
fn check_text(path: &str, diags: &[FileDiagnostics]) -> String {
    let found: Vec<_> = diags.iter().flat_map(|f| f.printed().map(move |d| (f, d))).collect();
    let errors = found.iter().filter(|(_, d)| d.is_error()).count();

    let mut out = format!("{path}: {errors} error(s), {} warning(s)\n", found.len() - errors);
    for (f, d) in found {
        let (line, column) = line_and_column(&f.text, d.span.start);
        let severity = if d.is_error() { "error" } else { "warning" };
        out.push_str(&format!("- {}:{} {severity} {}: {}", line + 1, column + 1, d.code, d.message));
        if let Some(help) = &d.help {
            out.push_str(&format!(" (help: {help})"));
        }

        out.push('\n');
    }

    out
}

fn initialize(params: &Value) -> Value {
    // The version of the client when the server knows it, else the newest one.
    let asked = params.get("protocolVersion").and_then(Value::as_str);
    let version = asked.filter(|v| VERSIONS.contains(v)).unwrap_or(VERSIONS[0]);

    json!({
        "protocolVersion": version,
        "capabilities": { "tools": { "listChanged": false } },
        "serverInfo": { "name": "phem", "title": "Phem", "version": env!("CARGO_PKG_VERSION") },
        "instructions": INSTRUCTIONS,
    })
}

/// The tools, with their JSON schemas. They only read: the check writes nothing.
fn tools() -> Vec<Value> {
    let tool = |name: &str, title: &str, description: &str, properties: Value, required: &[&str]| {
        json!({
            "name": name,
            "title": title,
            "description": description,
            "inputSchema": { "type": "object", "properties": properties, "required": required, "additionalProperties": false },
            "annotations": { "readOnlyHint": true, "openWorldHint": false },
        })
    };
    let package = json!({ "type": "string", "description": "Only the components of this Composer package (`app` for the app)" });

    let all = vec![
        tool(
            "phem_search",
            "Search Phem",
            "Search the docs of Phem, the components and the examples of the app and of its packages. Gives the best keys with their context: read them with phem_doc, phem_component or phem_examples. The same query always gives the same result.",
            json!({ "query": { "type": "string", "description": "The task or the words to search, for example `dialog with a form`" }, "limit": { "type": "integer", "minimum": 1, "maximum": 100, "description": "The number of results (20 by default)" } }),
            &["query"],
        ),
        tool(
            "phem_doc",
            "Read a Phem doc",
            "Read a doc of the installed version of Phem (`events`), one section (`events#poll-the-server`), or the guide of a package (`acme/ui`). With no key: the key of each section of the docs.",
            json!({ "key": { "type": "string", "description": "A doc, a doc section, or a package guide" } }),
            &[],
        ),
        tool(
            "phem_components",
            "List the components",
            "List the component families of the app and of the installed packages, one line each, with the sections of the package guides.",
            json!({ "package": package }),
            &[],
        ),
        tool(
            "phem_component",
            "Read a component",
            "Read one component: its docblock, its imports, its props with their types and defaults, the components that it goes inside, its parts (anatomy) and its examples.",
            json!({ "name": { "type": "string", "description": "The tag (`Dialog`) or the FQCN of the component" }, "package": package }),
            &["name"],
        ),
        tool(
            "phem_examples",
            "Read the examples",
            "With no name: all the examples of the packages. With a component: the examples that use it. With an example: its code as a complete file (imports, constants, the example and its helpers) to start from.",
            json!({ "name": { "type": "string", "description": "A component or an example (its name or its FQCN)" }, "package": package }),
            &[],
        ),
        tool(
            "phem_check",
            "Check a .phem file",
            "Compile the project with a `.phem` file and give the diagnostics of this file: the file on disk (`path`), or a code in place of it (`code`, with the `path` of the file that it replaces). Writes nothing.",
            json!({ "path": { "type": "string", "description": "The path of the file from the project root" }, "code": { "type": "string", "description": "The content of the file to check, in place of the file on disk" } }),
            &[],
        ),
    ];
    debug_assert!(all.iter().map(|t| t["name"].as_str()).eq(TOOLS.iter().map(|n| Some(*n))));

    all
}

/// A string argument, or `None`. Another type is an error.
fn string<'a>(args: &'a Map<String, Value>, key: &str) -> Result<Option<&'a str>, String> {
    match args.get(key) {
        None | Some(Value::Null) => Ok(None),
        Some(Value::String(s)) => Ok(Some(s)),
        Some(_) => Err(format!("`{key}` must be a string")),
    }
}

fn limit(args: &Map<String, Value>) -> Result<usize, String> {
    match args.get("limit") {
        None | Some(Value::Null) => Ok(20),
        Some(v) => v.as_u64().filter(|n| (1..=100).contains(n)).map(|n| n as usize).ok_or_else(|| "`limit` must be an integer from 1 to 100".into()),
    }
}

fn result(id: &Value, result: Value) -> Value {
    json!({ "jsonrpc": "2.0", "id": id, "result": result })
}

fn error(id: Value, code: i64, message: &str) -> Value {
    json!({ "jsonrpc": "2.0", "id": id, "error": { "code": code, "message": message } })
}

#[cfg(test)]
mod tests {
    use super::*;

    /// A server on the fixture project of phemc-knowledge: an app and the package `acme/ui`.
    fn server() -> Server<Vec<u8>> {
        let project = Path::new(env!("CARGO_MANIFEST_DIR")).join("../phemc-knowledge/tests/fixtures/project");

        Server { out: Vec::new(), project, src: PathBuf::from("src") }
    }

    /// The responses to the lines, one JSON value each.
    fn exchange(lines: &[Value]) -> Vec<Value> {
        let mut s = server();
        for l in lines {
            s.handle(&l.to_string());
        }

        let out = String::from_utf8(s.out).unwrap();
        out.lines().map(|l| serde_json::from_str(l).unwrap()).collect()
    }

    fn request(id: i64, method: &str, params: Value) -> Value {
        json!({ "jsonrpc": "2.0", "id": id, "method": method, "params": params })
    }

    /// The text and the error flag of a tool call.
    fn call(name: &str, arguments: Value) -> (String, bool) {
        let r = &exchange(&[request(1, "tools/call", json!({ "name": name, "arguments": arguments }))])[0];

        (r["result"]["content"][0]["text"].as_str().unwrap().to_string(), r["result"]["isError"].as_bool().unwrap())
    }

    #[test]
    fn initialize_agrees_on_a_version() {
        let r = exchange(&[
            request(1, "initialize", json!({ "protocolVersion": "2025-03-26", "capabilities": {}, "clientInfo": { "name": "test", "version": "1" } })),
            json!({ "jsonrpc": "2.0", "method": "notifications/initialized" }),
            request(2, "initialize", json!({ "protocolVersion": "1999-01-01" })),
            request(3, "ping", json!({})),
        ]);

        // The notification gets no response.
        assert_eq!(r.len(), 3);
        assert_eq!(r[0]["id"], 1);
        assert_eq!(r[0]["result"]["protocolVersion"], "2025-03-26");
        assert_eq!(r[0]["result"]["capabilities"], json!({ "tools": { "listChanged": false } }));
        assert_eq!(r[0]["result"]["serverInfo"]["name"], "phem");
        assert!(r[0]["result"]["instructions"].as_str().unwrap().contains("phem_check"));
        // A version that the server does not know: its newest one.
        assert_eq!(r[1]["result"]["protocolVersion"], VERSIONS[0]);
        assert_eq!(r[2], json!({ "jsonrpc": "2.0", "id": 3, "result": {} }));
    }

    #[test]
    fn the_tools_have_a_schema() {
        let tools = exchange(&[request(1, "tools/list", json!({}))])[0]["result"]["tools"].clone();
        let names: Vec<&str> = tools.as_array().unwrap().iter().map(|t| t["name"].as_str().unwrap()).collect();

        assert_eq!(names, ["phem_search", "phem_doc", "phem_components", "phem_component", "phem_examples", "phem_check"]);
        for t in tools.as_array().unwrap() {
            assert_eq!(t["inputSchema"]["type"], "object");
            assert_eq!(t["annotations"]["readOnlyHint"], true);
            assert!(!t["description"].as_str().unwrap().is_empty());
        }

        assert_eq!(tools[0]["inputSchema"]["required"], json!(["query"]));
    }

    #[test]
    fn the_tools_give_the_texts_of_the_commands() {
        let (search, error) = call("phem_search", json!({ "query": "dialog with a form", "limit": 3 }));
        assert!(!error);
        assert!(search.starts_with("# Search: dialog with a form"));
        assert!(search.contains("1. `Acme\\Ui\\Examples\\DialogFormDemo` (example)"));
        assert!(!search.contains("\n4. "));

        let (component, _) = call("phem_component", json!({ "name": "Dialog" }));
        assert!(component.starts_with("# Dialog — Acme\\Ui\\Dialog (acme/ui)"));
        assert!(component.contains("## Anatomy"));

        let (example, _) = call("phem_examples", json!({ "name": "DialogFormDemo" }));
        assert!(example.contains("use function Acme\\Ui\\{Button, DialogPopup, DialogTitle, DialogTrigger};"));

        let (components, _) = call("phem_components", json!({ "package": "acme/ui" }));
        assert!(components.contains("- `Dialog` A modal dialog.") && !components.contains("`Card`"));

        // With no key, the map of the docs. A guide needs the project.
        let (toc, _) = call("phem_doc", json!({}));
        assert!(toc.starts_with("# Phem docs: sections") && toc.contains("`events#poll-the-server`"));
        assert!(call("phem_doc", json!({ "key": "acme/ui#naming" })).0.contains("## Naming"));
    }

    #[test]
    fn a_missing_key_or_a_wrong_argument_is_a_tool_error() {
        let (text, error) = call("phem_component", json!({ "name": "Dialogg" }));
        assert!(error);
        assert!(text.starts_with("no component `Dialogg`. Did you mean `Acme\\Ui\\Dialog`"), "{text}");

        assert_eq!(call("phem_search", json!({})), ("`query` is required".to_string(), true));
        assert_eq!(call("phem_search", json!({ "query": "x", "limit": 0 })), ("`limit` must be an integer from 1 to 100".to_string(), true));
        assert_eq!(call("phem_doc", json!({ "key": 3 })), ("`key` must be a string".to_string(), true));
        assert_eq!(call("phem_check", json!({})), ("give `path`, `code`, or both".to_string(), true));
    }

    #[test]
    fn check_gives_the_diagnostics_of_a_file_or_a_code() {
        assert_eq!(call("phem_check", json!({ "path": "src/Ui/Card.phem" })), ("src/Ui/Card.phem: 0 error(s), 0 warning(s)\n".to_string(), false));

        // A code with an error: the diagnostics of the code only, with 1-based positions.
        let code = "<?phem\nnamespace App\\Ui;\nuse Phem\\Attribute\\Component;\nuse Phem\\Node;\n#[Component]\nfunction Broken(): Node { return <Card />; }\n";
        let (text, error) = call("phem_check", json!({ "path": "src/Ui/Broken.phem", "code": code }));
        assert!(!error);
        assert_eq!(text, "src/Ui/Broken.phem: 1 error(s), 0 warning(s)\n- 6:35 error E011: `<Card>` needs the prop `title`\n");

        // A code in place of a file of the project.
        let fixed = "<?phem\nnamespace App\\Ui;\nuse Phem\\Attribute\\Component;\nuse Phem\\Node;\n#[Component]\nfunction Card(): Node { return <p />; }\n";
        assert!(call("phem_check", json!({ "path": "src/Ui/Card.phem", "code": fixed })).0.starts_with("src/Ui/Card.phem: 0 error(s)"));
    }

    #[test]
    fn a_batch_gets_one_array_of_responses() {
        let r = exchange(&[json!([request(1, "ping", json!({})), json!({ "jsonrpc": "2.0", "method": "notifications/initialized" }), request(2, "tools/list", json!({}))])]);

        assert_eq!(r.len(), 1);
        let ids: Vec<&Value> = r[0].as_array().unwrap().iter().map(|m| &m["id"]).collect();
        assert_eq!(ids, [&json!(1), &json!(2)]);
        assert_eq!(exchange(&[json!([])])[0]["error"]["code"], -32600);
    }

    #[test]
    fn a_line_that_is_not_utf8_does_not_stop_the_server() {
        let mut s = server();
        let input: &[u8] = b"{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"ping\",\"x\":\"\xff\"}\n{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"ping\"}\n";
        s.serve(input).unwrap();

        let out: Vec<Value> = String::from_utf8(s.out).unwrap().lines().map(|l| serde_json::from_str(l).unwrap()).collect();
        assert_eq!(out[0]["error"]["code"], -32700);
        assert_eq!(out[1], json!({ "jsonrpc": "2.0", "id": 2, "result": {} }));
    }

    #[test]
    fn a_request_with_a_null_id_gets_a_response() {
        assert_eq!(exchange(&[json!({ "jsonrpc": "2.0", "id": null, "method": "ping" })])[0], json!({ "jsonrpc": "2.0", "id": null, "result": {} }));
    }

    #[test]
    fn check_reads_only_the_files_of_the_project() {
        for path in ["/etc/passwd", "../../../Cargo.toml", "src/../../x.phem"] {
            assert_eq!(call("phem_check", json!({ "path": path })), ("`path` must be a relative path in the project, with no `..`".to_string(), true));
        }
    }

    #[test]
    fn the_command_line_runs_the_server() {
        use clap::Parser;

        let cli = crate::Cli::try_parse_from(["phemc", "mcp", "--src", "app/src"]).unwrap();
        assert!(matches!(cli.cmd, crate::Cmd::Mcp { src } if src == Path::new("app/src")));
    }

    #[test]
    fn protocol_errors() {
        let r = exchange(&[
            request(1, "tools/call", json!({ "name": "rm_rf" })),
            request(2, "resources/list", json!({})),
            json!({ "jsonrpc": "2.0", "id": 3, "result": {} }),
        ]);

        // A response of the client gets nothing.
        assert_eq!(r.len(), 2);
        assert_eq!(r[0]["error"], json!({ "code": -32602, "message": "unknown tool `rm_rf`" }));
        assert_eq!(r[1]["error"]["code"], -32601);

        let mut s = server();
        s.handle("{not json");
        let parse: Value = serde_json::from_slice(&s.out).unwrap();
        assert_eq!((parse["id"].clone(), parse["error"]["code"].clone()), (Value::Null, json!(-32700)));
    }
}
