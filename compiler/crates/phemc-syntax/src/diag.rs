use crate::Span;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Severity {
    Error,
    Warning,
}

/// A compiler message attached to a source span.
#[derive(Clone, Debug)]
pub struct Diagnostic {
    pub severity: Severity,
    pub code: &'static str,
    pub message: String,
    pub span: Span,
    pub label: Option<String>,
    pub help: Option<String>,
}

impl Diagnostic {
    pub fn error(code: &'static str, message: impl Into<String>, span: Span) -> Self {
        Self { severity: Severity::Error, code, message: message.into(), span, label: None, help: None }
    }

    pub fn warning(code: &'static str, message: impl Into<String>, span: Span) -> Self {
        Self { severity: Severity::Warning, code, message: message.into(), span, label: None, help: None }
    }

    pub fn with_label(mut self, label: impl Into<String>) -> Self {
        self.label = Some(label.into());
        self
    }

    pub fn with_help(mut self, help: impl Into<String>) -> Self {
        self.help = Some(help.into());
        self
    }

    pub fn is_error(&self) -> bool {
        self.severity == Severity::Error
    }
}

/// Diagnostic codes, so tests and docs can refer to them.
pub mod codes {
    pub const SYNTAX: &str = "E001";
    pub const UNCLOSED: &str = "E002";
    pub const MISMATCHED_CLOSE: &str = "E003";
    pub const VOID_NOT_CLOSED: &str = "E004";
    pub const MULTIPLE_ROOTS: &str = "E005";
    pub const PLAIN_OPEN_TAG: &str = "E006";
    pub const UNKNOWN_COMPONENT: &str = "E010";
    pub const UNKNOWN_PROP: &str = "E011";
    pub const NO_CHILDREN: &str = "E012";
    pub const UNKNOWN_SLOT: &str = "E013";
    pub const DUPLICATE_COMPONENT: &str = "E014";
    pub const MISSING_CONTEXT: &str = "E015";
    pub const REPLACEMENT_MISMATCH: &str = "E016";
    pub const INVALID_REPLACEMENT: &str = "E017";
    pub const UNKNOWN_ENUM_VALUE: &str = "E018";
    pub const HOOK_REMOVED: &str = "E020";
    pub const UNDEFINED_VARIABLE: &str = "E030";
    pub const MARKUP_IN_EXPRESSION: &str = "E031";
    pub const BAD_ATTRIBUTE: &str = "E032";
    pub const INVALID_BIND: &str = "E033";
    pub const CLASS_WITHOUT_RENDER: &str = "E040";
    pub const READONLY_STATE: &str = "E041";
    pub const INVALID_PROP: &str = "E042";
    pub const INVALID_REST_PROP: &str = "E043";
    pub const INVALID_CONTEXT: &str = "E044";
    pub const ABSTRACT_COMPONENT: &str = "E045";
    pub const RESERVED_HANDLER: &str = "E046";
    pub const INVALID_HANDLER_CALL: &str = "E047";
    pub const EXPORT_DEFAULT: &str = "E050";
    pub const ROUTE_NOT_COMPONENT: &str = "E052";
    pub const BROADCAST_NOT_COMPONENT: &str = "E053";
    pub const ASYNC_NOT_METHOD: &str = "E054";
    pub const ASYNC_WITHOUT_PROMISE: &str = "E055";
    pub const PAGE_AS_TAG: &str = "E056";
    pub const INVALID_BROADCAST_KEY: &str = "E057";
    pub const INVALID_VALIDATED: &str = "E058";
    pub const ERROR_PAGE_NOT_FUNCTION: &str = "E059";
    pub const ERROR_PAGE_IN_PACKAGE: &str = "E060";
    pub const INVALID_ERROR: &str = "E061";
    pub const MISSING_KEY: &str = "W002";
    pub const UNKNOWN_EVENT: &str = "W003";
    pub const FOREIGN_ATTRIBUTE: &str = "W004";
    pub const TITLE_IN_MARKUP: &str = "W005";
    pub const TIMING_WITHOUT_HANDLER: &str = "W006";
    pub const LIVE_WITHOUT_BIND: &str = "W007";
    pub const IMPURE_RENDER: &str = "W008";
}
