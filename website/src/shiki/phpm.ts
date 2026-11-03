import type { LanguageRegistration } from 'shiki';
import grammar from '../../../editors/vscode/syntaxes/phpm.tmLanguage.json';

// The grammar of the editor plugins also colors the code of the site.
export const phpm = { ...grammar, name: 'phpm', embeddedLangs: ['php', 'css', 'javascript'] } as unknown as LanguageRegistration;
