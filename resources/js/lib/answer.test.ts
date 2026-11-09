import { describe, expect, it } from 'vitest';
import { inline, parseAnswer } from './answer';

describe('parseAnswer', () => {
    it('splits paragraphs and bullet lists', () => {
        const blocks = parseAnswer('Revenue rose 4%.\n\n- Lahore led\n- Karachi flat\nThat is all.');
        expect(blocks.map((b) => b.type)).toEqual(['p', 'ul', 'p']);
        expect(blocks[1]).toEqual({ type: 'ul', items: [[{ text: 'Lahore led', bold: false }], [{ text: 'Karachi flat', bold: false }]] });
    });

    it('keeps markup as text and only recognises bold runs', () => {
        expect(inline('<b>x</b> and **y**')).toEqual([
            { text: '<b>x</b> and ', bold: false },
            { text: 'y', bold: true },
        ]);
    });

    it('drops heading markers instead of rendering them', () => {
        expect(parseAnswer('## Summary')).toEqual([{ type: 'p', parts: [{ text: 'Summary', bold: false }] }]);
    });
});
