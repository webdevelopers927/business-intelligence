/**
 * Turn the analyst's plain-text answer into safe render blocks: paragraphs,
 * "- " bullet lists and **bold** runs. No HTML is ever interpreted, so model
 * output can't inject markup.
 */
export type Inline = { text: string; bold: boolean };
export type AnswerBlock = { type: 'p'; parts: Inline[] } | { type: 'ul'; items: Inline[][] };

export function inline(text: string): Inline[] {
    const parts: Inline[] = [];
    const re = /\*\*(.+?)\*\*/g;
    let last = 0;
    for (let m = re.exec(text); m !== null; m = re.exec(text)) {
        if (m.index > last) parts.push({ text: text.slice(last, m.index), bold: false });
        parts.push({ text: m[1]!, bold: true });
        last = m.index + m[0].length;
    }
    if (last < text.length) parts.push({ text: text.slice(last), bold: false });
    return parts;
}

export function parseAnswer(text: string): AnswerBlock[] {
    const blocks: AnswerBlock[] = [];
    let paragraph: string[] = [];
    let list: Inline[][] | null = null;

    const flushParagraph = () => {
        if (paragraph.length) blocks.push({ type: 'p', parts: inline(paragraph.join(' ')) });
        paragraph = [];
    };
    const flushList = () => {
        if (list?.length) blocks.push({ type: 'ul', items: list });
        list = null;
    };

    for (const raw of text.replace(/\r\n/g, '\n').split('\n')) {
        const line = raw.trim();
        const bullet = /^[-*•]\s+(.*)$/.exec(line);
        if (bullet) {
            flushParagraph();
            list ??= [];
            list.push(inline(bullet[1]!));
        } else if (line === '') {
            flushParagraph();
            flushList();
        } else {
            flushList();
            paragraph.push(line.replace(/^#+\s*/, ''));
        }
    }
    flushParagraph();
    flushList();

    return blocks;
}
