import HashMap "mo:base/HashMap";
import Text "mo:base/Text";
import Time "mo:base/Time";
import Array "mo:base/Array";
import Iter "mo:base/Iter";
import Buffer "mo:base/Buffer";
import Types "./types";

actor Memory {

  // Stable storage for upgrades
  stable var memoriesEntries : [(Text, Types.MemoryRecord)] = [];

  // Runtime HashMap keyed by "user_id:record_index"
  var memories : HashMap.HashMap<Text, Types.MemoryRecord> = HashMap.HashMap(
    100, Text.equal, Text.hash
  );

  var nextId : Nat = 0;

  // Reconstruct from stable storage on upgrade
  system func preupgrade() {
    memoriesEntries := Iter.toArray(memories.entries());
  };

  system func postupgrade() {
    memories := HashMap.fromIter(
      memoriesEntries.vals(),
      100, Text.equal, Text.hash
    );
    memoriesEntries := [];
  };

  // Store a memory record for a user/session
  public func store_memory(req : Types.StoreRequest) : async Text {
    let id = Text.concat(req.user_id, Text.concat(":", Nat.toText(nextId)));
    nextId += 1;

    let record : Types.MemoryRecord = {
      user_id = req.user_id;
      session_id = req.session_id;
      content = req.content;
      timestamp = Time.now();
      metadata = req.metadata;
    };

    memories.put(id, record);
    id
  };

  // Get all memories for a user
  public query func get_memories(user_id : Text) : async [Types.MemoryResponse] {
    let buf = Buffer.Buffer<Types.MemoryResponse>(10);

    for ((id, record) in memories.entries()) {
      if (record.user_id == user_id) {
        buf.add({
          id = id;
          user_id = record.user_id;
          session_id = record.session_id;
          content = record.content;
          timestamp = record.timestamp;
          metadata = record.metadata;
        });
      };
    };

    Buffer.toArray(buf)
  };

  // Get memories for a specific session
  public query func get_memories_by_session(session_id : Text) : async [Types.MemoryResponse] {
    let buf = Buffer.Buffer<Types.MemoryResponse>(10);

    for ((id, record) in memories.entries()) {
      if (record.session_id == session_id) {
        buf.add({
          id = id;
          user_id = record.user_id;
          session_id = record.session_id;
          content = record.content;
          timestamp = record.timestamp;
          metadata = record.metadata;
        });
      };
    };

    Buffer.toArray(buf)
  };

  // List the most recent N memories across all users (for inspector dashboard)
  public query func list_recent_memories(limit : Nat) : async [Types.MemoryResponse] {
    let buf = Buffer.Buffer<Types.MemoryResponse>(limit);
    var count = 0;

    for ((id, record) in memories.entries()) {
      if (count < limit) {
        buf.add({
          id = id;
          user_id = record.user_id;
          session_id = record.session_id;
          content = record.content;
          timestamp = record.timestamp;
          metadata = record.metadata;
        });
        count += 1;
      };
    };

    Buffer.toArray(buf)
  };

  // Delete a specific memory record
  public func delete_memory(id : Text) : async Bool {
    switch (memories.remove(id)) {
      case (?_) true;
      case null false;
    }
  };

  // Health check
  public query func health() : async Text {
    "ok"
  };
};
