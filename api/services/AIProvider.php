<?php

/**
 * Interface for AI providers
 * Defines methods for chat completions and embeddings in OpenAI-compatible format
 */
interface AIProvider {
   /**
    * Perform chat completion (non-streaming)
    *
    * @param array $messages OpenAI format messages array
    * @param array $options Additional options (temperature, max_tokens, etc.)
    * @return array OpenAI-compatible response format
    * @throws Exception on API errors
    */
   public function chatCompletions($messages, $options = []);

   /**
    * Perform streaming chat completion
    *
    * @param array $messages OpenAI format messages array
    * @param array $options Additional options (temperature, max_tokens, etc.)
    * @return void Streams OpenAI-compatible SSE format
    * @throws Exception on API errors
    */
   public function streamChatCompletions($messages, $options = []);

   /**
    * Generate embeddings for text
    *
    * @param string|array $input Text or array of texts to embed
    * @param array $options Additional options (model, etc.)
    * @return array OpenAI-compatible embeddings response format
    * @throws Exception on API errors
    */
   public function createEmbeddings($input, $options = []);
}
?>