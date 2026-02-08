//
// Apple Foundation Models bridge for PhpBot.
//
// Supports multiple operations:
//   - classify: Task classification with JSON output
//   - summarize: Content summarization with instructions
//   - generate: General-purpose text completion
//
// Requires: macOS 26+ (Tahoe) with Apple Intelligence enabled.
//
// Compile:
//   swiftc -parse-as-library -framework FoundationModels -O \
//     -o bin/apple-fm-classify bin/apple-fm-classify.swift
//
// Usage:
//   echo '{"prompt":"Classify this..."}' | bin/apple-fm-classify
//   echo '{"prompt":"Summarize this...","instructions":"Be concise."}' | bin/apple-fm-classify
//
// Input (stdin):  JSON with "prompt" and optional "instructions" keys
// Output (stdout): JSON with "content", "provider", "model" keys
//

import Foundation

#if canImport(FoundationModels)
import FoundationModels

// ----------------------------------------------------------------------------
// Input/Output structs
// ----------------------------------------------------------------------------

struct FMRequest: Decodable {
    let prompt: String
    let instructions: String?
    let maxTokens: Int?

    enum CodingKeys: String, CodingKey {
        case prompt
        case instructions
        case maxTokens = "max_tokens"
    }
}

struct FMResponse: Encodable {
    let content: String
    let provider: String
    let model: String
    let inputChars: Int
    let outputChars: Int

    enum CodingKeys: String, CodingKey {
        case content
        case provider
        case model
        case inputChars = "input_chars"
        case outputChars = "output_chars"
    }
}

struct ErrorResponse: Encodable {
    let error: String
    let provider: String
}

// ----------------------------------------------------------------------------
// Main entry point
// ----------------------------------------------------------------------------

@main
struct AppleFMBridge {
    static func main() async {
        do {
            // Read JSON from stdin
            let inputData = FileHandle.standardInput.readDataToEndOfFile()

            guard !inputData.isEmpty else {
                outputError("No input received on stdin")
                return
            }

            let request = try JSONDecoder().decode(FMRequest.self, from: inputData)

            // Create a session with optional instructions for better quality
            let session: LanguageModelSession

            if let instructions = request.instructions, !instructions.isEmpty {
                session = LanguageModelSession(instructions: instructions)
            } else {
                session = LanguageModelSession()
            }

            // Send the prompt and get the response
            let response = try await session.respond(to: request.prompt)
            let content = response.content

            // Output the result as JSON
            let result = FMResponse(
                content: content,
                provider: "apple_fm",
                model: "apple-on-device",
                inputChars: request.prompt.count + (request.instructions?.count ?? 0),
                outputChars: content.count
            )
            let encoder = JSONEncoder()
            let outputData = try encoder.encode(result)
            FileHandle.standardOutput.write(outputData)
            FileHandle.standardOutput.write("\n".data(using: .utf8)!)

        } catch {
            outputError(error.localizedDescription)
        }
    }

    static func outputError(_ message: String) {
        let errorResponse = ErrorResponse(error: message, provider: "apple_fm")
        if let data = try? JSONEncoder().encode(errorResponse) {
            FileHandle.standardError.write(data)
            FileHandle.standardError.write("\n".data(using: .utf8)!)
        }
        _exit(1)
    }
}

#else

// Fallback for systems without FoundationModels framework
@main
struct AppleFMBridge {
    static func main() {
        let msg = "{\"error\":\"FoundationModels framework not available. Requires macOS 26+.\",\"provider\":\"apple_fm\"}"
        FileHandle.standardError.write(msg.data(using: .utf8)!)
        FileHandle.standardError.write("\n".data(using: .utf8)!)
        _exit(1)
    }
}

#endif
