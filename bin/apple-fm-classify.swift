//
// Apple Foundation Models classifier bridge for PhpBot.
//
// Requires: macOS 26+ (Tahoe) with Apple Intelligence enabled.
//
// Compile:
//   swiftc -parse-as-library -framework FoundationModels -O \
//     -o bin/apple-fm-classify bin/apple-fm-classify.swift
//
// Usage:
//   echo '{"prompt":"Classify this..."}' | bin/apple-fm-classify
//
// Input (stdin):  JSON with "prompt" key
// Output (stdout): JSON with "content" key
//

import Foundation

#if canImport(FoundationModels)
import FoundationModels

// ----------------------------------------------------------------------------
// Input/Output structs
// ----------------------------------------------------------------------------

struct ClassifyRequest: Decodable {
    let prompt: String
    let maxTokens: Int?

    enum CodingKeys: String, CodingKey {
        case prompt
        case maxTokens = "max_tokens"
    }
}

struct ClassifyResponse: Encodable {
    let content: String
    let provider: String
    let model: String
}

struct ErrorResponse: Encodable {
    let error: String
    let provider: String
}

// ----------------------------------------------------------------------------
// Main entry point
// ----------------------------------------------------------------------------

@main
struct AppleFMClassify {
    static func main() async {
        do {
            // Read JSON from stdin
            let inputData = FileHandle.standardInput.readDataToEndOfFile()

            guard !inputData.isEmpty else {
                outputError("No input received on stdin")
                return
            }

            let request = try JSONDecoder().decode(ClassifyRequest.self, from: inputData)

            // Create a session with the on-device model
            let session = LanguageModelSession()

            // Send the classification prompt
            let response = try await session.respond(to: request.prompt)
            let content = response.content

            // Output the result as JSON
            let result = ClassifyResponse(
                content: content,
                provider: "apple_fm",
                model: "apple-on-device"
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
struct AppleFMClassify {
    static func main() {
        let msg = "{\"error\":\"FoundationModels framework not available. Requires macOS 26+.\",\"provider\":\"apple_fm\"}"
        FileHandle.standardError.write(msg.data(using: .utf8)!)
        FileHandle.standardError.write("\n".data(using: .utf8)!)
        _exit(1)
    }
}

#endif
