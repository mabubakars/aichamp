/**
 * Strips thinking/reasoning sections from AI response content.
 * Handles multiple formats:
 *   1. <think>...</think> tags (DeepSeek-style)
 *   2. <thinking>...</thinking> tags
 *   3. [THINKING]...[/THINKING] bracket style
 *   4. Plain-text preambles like "Here's a thinking process:" followed by a numbered list
 */
export function stripThinking(content) {
  if (!content) return content;

  let result = content;

  // 1. Remove XML-style <think> blocks (including multiline)
  result = result.replace(/<think>[\s\S]*?<\/think>/gi, "");

  // 2. Remove <thinking> blocks
  result = result.replace(/<thinking>[\s\S]*?<\/thinking>/gi, "");

  // 3. Remove [THINKING] bracket blocks
  result = result.replace(/\[THINKING\][\s\S]*?\[\/THINKING\]/gi, "");

  // 4. Remove plain-text "thinking process" preambles.
  //    Pattern: a line that says "Here's a thinking process:" (or similar),
  //    followed by a numbered/bulleted list, up to the first blank line that
  //    precedes normal prose.
  //
  //    We detect the boundary by looking for:
  //      - A line starting with a number+dot ("1.") or bullet
  //      - Followed by more such lines
  //      - Then a blank line (end of the list block)
  //
  //    Strategy: find the preamble header, then skip forward until we find
  //    a blank line that isnds the list, and drop everything before it.
  result = result.replace(
    /^[\s\S]*?(?:here(?:'s| is)(?: a)? thinking(?: process)?|thinking process|let me (?:think|reason)|step[- ]by[- ]step reasoning)[:\s]*\n((?:(?:\d+\.|[-*•])[^\n]*\n?)+)/im,
    ""
  );

  // 5. Trim any leading/trailing whitespace left behind
  result = result.trim();

  return result;
}