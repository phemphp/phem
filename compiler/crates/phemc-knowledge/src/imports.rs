//! The `use` statements of a code that comes from a file: the imports of the file that the code
//! uses, as the file writes them. An agent that copies an example gets the imports with it.

use phemc_analyze::FileInfo;
use serde::Serialize;

/// An import of a file.
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
pub struct Import {
    /// `use function`.
    pub function: bool,
    pub fqn: String,
    /// The name in the code: the last segment of the FQN, or the `as` alias.
    pub alias: String,
}

/// The imports of a file that a code uses (the code is a part of the file).
pub fn used_imports(file: &FileInfo, code: &str) -> Vec<Import> {
    let lower = code.to_ascii_lowercase();
    let mut out = Vec::new();

    for (function, uses) in [(false, &file.class_uses), (true, &file.function_uses)] {
        for (alias, fqn) in uses {
            // The keys are lowercase. A plain import has the spelling of its FQN, and an `as`
            // alias the spelling of the code.
            let last = fqn.rsplit('\\').next().unwrap_or(fqn);
            let name = match last.eq_ignore_ascii_case(alias) {
                true => Some(last.to_string()),
                false => word_at(&lower, alias).map(|at| code[at..at + alias.len()].to_string()),
            };

            if let Some(name) = name.filter(|n| has_word(code, n)) {
                out.push(Import { function, fqn: fqn.clone(), alias: name });
            }
        }
    }

    out.sort_by(|a, b| (a.function, &a.fqn).cmp(&(b.function, &b.fqn)));

    out
}

/// The `use` statements of imports: the classes, then the functions, with one group for each
/// namespace (`use Phem\Ui\{Dialog, DialogViewport};`), in the alphabetical order.
pub fn use_block(imports: &[Import]) -> Vec<String> {
    let mut lines: Vec<(bool, String)> = Vec::new();
    let mut groups: Vec<(bool, String, Vec<String>)> = Vec::new();

    for i in imports {
        let (namespace, last) = i.fqn.rsplit_once('\\').unwrap_or(("", i.fqn.as_str()));
        let keyword = if i.function { "use function" } else { "use" };

        // An alias, or a name with no namespace, has a statement of its own.
        if !last.eq_ignore_ascii_case(&i.alias) || namespace.is_empty() {
            let alias = if last.eq_ignore_ascii_case(&i.alias) { String::new() } else { format!(" as {}", i.alias) };
            lines.push((i.function, format!("{keyword} {}{alias};", i.fqn)));
            continue;
        }

        match groups.iter_mut().find(|(f, ns, _)| *f == i.function && ns == namespace) {
            Some((_, _, names)) => names.push(last.to_string()),
            None => groups.push((i.function, namespace.to_string(), vec![last.to_string()])),
        }
    }

    for (function, namespace, mut names) in groups {
        names.sort_by_key(|n| n.to_ascii_lowercase());
        names.dedup();

        let keyword = if function { "use function" } else { "use" };
        let names = if names.len() == 1 { names[0].clone() } else { format!("{{{}}}", names.join(", ")) };
        lines.push((function, format!("{keyword} {namespace}\\{names};")));
    }

    // `Phem\{Html, Node}` sorts as `Phem\Html`.
    lines.sort_by_key(|(function, line)| (*function, line.to_ascii_lowercase().replace("\\{", "\\")));

    lines.into_iter().map(|(_, line)| line).collect()
}

/// Whether the code has a name as a whole word: not in a longer name or a variable. A name
/// followed by `\` (`Assert\NotBlank`) counts.
pub fn has_word(code: &str, name: &str) -> bool {
    word_at(code, name).is_some()
}

fn word_at(code: &str, name: &str) -> Option<usize> {
    let is_word = |c: char| c.is_alphanumeric() || c == '_';

    code.match_indices(name).map(|(i, _)| i).find(|&i| {
        let before = code[..i].chars().next_back().is_none_or(|c| !is_word(c) && c != '$' && c != '\\');
        let after = code[i + name.len()..].chars().next().is_none_or(|c| !is_word(c));
        before && after
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    fn import(function: bool, fqn: &str, alias: &str) -> Import {
        Import { function, fqn: fqn.into(), alias: alias.into() }
    }

    #[test]
    fn gives_the_imports_that_the_code_uses() {
        let src = "<?phem\nnamespace App;\nuse Phem\\Node;\nuse Phem\\Html;\nuse Symfony\\Component\\Validator\\Constraints as Assert;\nuse function Acme\\Ui\\{Button, Card};\n";
        let lexed = phemc_syntax::php::lex(src).unwrap();
        let file = phemc_analyze::scan("App.phem", src, &lexed);
        let code = "#[Assert\\NotBlank] function A(): Node { return <Button>a card, an html text</Button>; }";

        // A word of a text with another case is not a use.
        assert_eq!(used_imports(&file, code), [import(false, "Phem\\Node", "Node"), import(false, "Symfony\\Component\\Validator\\Constraints", "Assert"), import(true, "Acme\\Ui\\Button", "Button")]);
    }

    #[test]
    fn groups_the_names_of_a_namespace() {
        let imports = [
            import(true, "Acme\\Ui\\DialogTrigger", "DialogTrigger"),
            import(false, "Acme\\Ui\\Dialog", "Dialog"),
            import(false, "Phem\\Node", "Node"),
            import(false, "Phem\\Attribute\\Component", "Component"),
            import(false, "Phem\\Html", "Html"),
            import(false, "Symfony\\Component\\Validator\\Constraints", "Assert"),
            import(true, "Acme\\Ui\\DialogPopup", "DialogPopup"),
        ];

        assert_eq!(
            use_block(&imports),
            [
                "use Acme\\Ui\\Dialog;",
                "use Phem\\Attribute\\Component;",
                "use Phem\\{Html, Node};",
                "use Symfony\\Component\\Validator\\Constraints as Assert;",
                "use function Acme\\Ui\\{DialogPopup, DialogTrigger};",
            ]
        );
    }

    #[test]
    fn finds_whole_words() {
        assert!(has_word("<Card />", "Card"));
        assert!(has_word("Assert\\NotBlank", "Assert"));
        assert!(!has_word("<CardTitle />", "Card"));
        assert!(!has_word("$card = Ui\\Card;", "card"));
    }
}
